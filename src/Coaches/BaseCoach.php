<?php

declare(strict_types=1);

namespace Sisly\Coaches;

use Sisly\CoE\CoEEngine;
use Sisly\Contracts\CoachInterface;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\DTOs\CoETrace;
use Sisly\DTOs\Session;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * Base class for all Sisly coaches.
 *
 * Provides common functionality for prompt loading, LLM interaction, and CoE processing.
 */
abstract class BaseCoach implements CoachInterface
{
    protected PromptLoader $promptLoader;
    protected CoEEngine $coeEngine;
    protected IdentityQuestionDetector $identityDetector;
    protected CredentialQuestionDetector $credentialDetector;

    public function __construct(
        protected readonly LLMProviderInterface $llm,
        ?PromptLoader $promptLoader = null,
        ?CoEEngine $coeEngine = null,
        ?IdentityQuestionDetector $identityDetector = null,
        ?CredentialQuestionDetector $credentialDetector = null,
    ) {
        $this->promptLoader = $promptLoader ?? new PromptLoader();
        $this->coeEngine = $coeEngine ?? new CoEEngine($llm);
        $this->identityDetector = $identityDetector ?? new IdentityQuestionDetector();
        $this->credentialDetector = $credentialDetector ?? new CredentialQuestionDetector();
    }

    /**
     * Short, per-language role description used in the hardcoded
     * identity reply. Subclasses must localize for at least 'en' and 'ar'.
     */
    abstract public function getRoleDescription(string $language): string;

    /**
     * Process a user message and generate a response.
     *
     * @return array{response: string, arabic_mirror: ?string, coe_trace: ?CoETrace}
     */
    public function process(Session $session, string $message): array
    {
        // Credential / human-ness questions ("are you a therapist?", "are you human?",
        // "هل انت حقيقية؟") bypass the LLM and return a deterministic disclaimer.
        // Checked BEFORE the identity question because credential questions need a
        // different shape of reply (disclaimer vs name+role) and the model must
        // never claim a clinical credential, even probabilistically.
        if ($this->credentialDetector->isCredentialQuestion($message)) {
            return [
                'response' => $this->buildHardcodedCredentialReply($session),
                'arabic_mirror' => null,
                'coe_trace' => null,
            ];
        }

        // Identity questions ("what's your name?", "ما اسمك؟", etc.) bypass the
        // LLM entirely and return a deterministic reply. Eliminates flakiness
        // where the model would ignore the meta-question rule and run the
        // standard intake script instead.
        if ($this->identityDetector->isIdentityQuestion($message)) {
            return [
                'response' => $this->buildHardcodedIdentityReply($session),
                'arabic_mirror' => null,
                'coe_trace' => null,
            ];
        }

        // Build the full prompt with language instruction
        $systemPrompt = $this->buildFullSystemPrompt($session);
        $statePrompt = $this->getStatePrompt($session->state);

        // Run CoE reasoning
        $coeResult = $this->coeEngine->reason($session, $message, $systemPrompt);

        // Generate response using LLM (in user's preferred language)
        $response = $this->generateResponse($session, $message, $systemPrompt, $statePrompt);

        // Note: Arabic mirror is no longer generated - responses are single-language
        // based on user preference (EN or AR, not both)

        return [
            'response' => $response,
            'arabic_mirror' => null, // Single-language mode: no mirror needed
            'coe_trace' => $session->preferences->includeCoETrace ? $coeResult : null,
        ];
    }

    /**
     * Build the deterministic identity reply.
     *
     * Coach name stays in Latin script in both languages — coach names are
     * brand identifiers and should be consistent across languages.
     */
    protected function buildHardcodedIdentityReply(Session $session): string
    {
        $name = $this->getName();
        $role = $this->getRoleDescription($session->preferences->language);

        if ($session->preferences->language === 'ar') {
            return "أنا {$name}، {$role}. شو في بالك اليوم؟";
        }

        return "I'm {$name}, {$role}. What's on your mind today?";
    }

    /**
     * Build the deterministic credential / human-ness reply.
     *
     * Disclaims any clinical credential and any humanity claim. Required by
     * NIST-AI-RMF / NHS-DCB0129 posture: the model must not represent itself
     * as a licensed professional or a human, even when the persona prompt
     * has been enriched with backstory.
     *
     * Coach name stays in Latin script in both languages.
     */
    protected function buildHardcodedCredentialReply(Session $session): string
    {
        $name = $this->getName();

        if ($session->preferences->language === 'ar') {
            return "أنا {$name}، مدربة ذكاء اصطناعي — مو طبيبة ولا معالجة. ما أقدر أشخّص أو أعطي نصايح طبية، بس أنا هنا أساعدك تهدّأ. شو في بالك؟";
        }

        return "I'm {$name}, an AI coach — not a clinician or human. I can't diagnose or give medical advice, but I'm here to help you regulate. What's on your mind?";
    }

    /**
     * Build the system prompt: global rules + coach-specific content +
     * (optionally) a one-turn transition bridge.
     *
     * The bridge fires only on the turn immediately following an FSM
     * state transition (detected via Session::$lastTransitionAt). It
     * instructs the bot to acknowledge continuity from the previous
     * coaching phase before easing into the new one — preventing the
     * abrupt tone-shift that the bare new-state prompt would cause.
     *
     * Language enforcement is handled by buildLanguageRule() via
     * getIdentityAnchor() which is appended last in generateResponse().
     * Single source of truth.
     */
    protected function buildFullSystemPrompt(Session $session): string
    {
        $globalRules = $this->promptLoader->loadGlobal('rules');
        $coachSystem = $this->getSystemPrompt($session->state);
        $bridge = $this->buildTransitionBridge($session);

        $prompt = <<<PROMPT
{$globalRules}

---

{$coachSystem}
PROMPT;

        if ($bridge !== '') {
            $prompt .= "\n\n---\n\n## Transition Bridge (this turn only)\n\n{$bridge}";
        }

        return $prompt;
    }

    /**
     * Resolve the transition bridge for this turn (or empty string).
     *
     * Returns non-empty content only when:
     * 1. A transition occurred on the previous turn (i.e. lastTransitionAt
     *    equals the current turnCount minus 1 — the user has just sent the
     *    first message after a state change), AND
     * 2. The from→to pair (with optional reason qualifier) maps to a known
     *    section in global/transitions.md.
     *
     * Bridges fire for ONE TURN only.
     */
    protected function buildTransitionBridge(Session $session): string
    {
        if ($session->lastTransitionFromState === null) {
            return '';
        }

        // Bridge fires on the FIRST turn after a transition. The transition
        // sets lastTransitionAt to the turnCount at that moment. By the time
        // BaseCoach runs (after addTurn(user) in SislyManager), turnCount has
        // incremented by exactly 1 — so a current-cycle bridge is detected
        // when lastTransitionAt === turnCount - 1.
        if ($session->lastTransitionAt !== $session->turnCount - 1) {
            return '';
        }

        return $this->promptLoader->loadTransitionBridge(
            from: $session->lastTransitionFromState,
            to: $session->state,
            reason: $session->lastTransitionReason,
        );
    }

    /**
     * Final identity + language anchor appended last in the system prompt.
     *
     * Lands after all state-specific scripts so it wins recency bias over
     * any conflicting "include Arabic mirror" content baked into the
     * coach prompts. Also enforces the SessionPreferences.language and
     * arabicMirror flags, which the prompt body otherwise ignores.
     */
    protected function getIdentityAnchor(Session $session): string
    {
        $name = $this->getName();
        $languageRule = $this->buildLanguageRule($session);

        return <<<PROMPT
=== FINAL OVERRIDE (highest priority — read this last) ===

Your name is {$name}. Sisly is the platform, not your name.

You are an AI coach. You are NOT a psychologist, therapist, psychiatrist, doctor, counselor, clinician, or human being. NEVER claim to be any of these. NEVER claim years of clinical experience, professional credentials, licenses, or the ability to diagnose. Any "experience" mentioned in your persona is inner orientation — not a credential you assert to the user.

If the user's latest message is a direct question about who you are, your name, or what this is — in any language (e.g., "what's your name", "who are you", "ما اسمك", "مين انت") — your reply MUST:
- begin with your name "{$name}",
- give a one-line role description,
- NOT run the standard greet-and-explore script,
- NOT invent context the user hasn't given (no "your meeting", "your thoughts", etc.),
- NOT contain the word "Sisly".

If the user asks whether you are a therapist, doctor, real person, human, or AI (e.g., "are you a therapist", "are you human", "هل انت حقيقية") — your reply MUST disclaim that you are an AI coach, not a clinician or human, and offer to help. Do NOT pretend to be human. Do NOT claim a credential.

For all other messages, follow the coaching script above as normal.

{$languageRule}
PROMPT;
    }

    /**
     * Build the strict language rule from session preferences.
     *
     * Overrides any "include Arabic mirror" instructions in upstream
     * prompts. Honors SessionPreferences.language (en|ar) and
     * SessionPreferences.arabicMirror (only meaningful for en).
     */
    protected function buildLanguageRule(Session $session): string
    {
        $language = $session->preferences->language;
        $arabicMirror = $session->preferences->arabicMirror;

        if ($language === 'ar') {
            return <<<PROMPT
=== STRICT LANGUAGE RULE ===
The user prefers Arabic. Respond ONLY in Gulf Arabic (Khaleeji).
- Do NOT include any English text.
- EXCEPTION: Always write coach names (MEETLY, VENTO, LOOPY, PRESSO, BOOSTLY) in Latin script. Do not transliterate them.
- Ignore any earlier instruction that suggests bilingual or English output.
PROMPT;
        }

        if ($arabicMirror) {
            return <<<PROMPT
=== STRICT LANGUAGE RULE ===
The user prefers English. Respond in English.
- The body of your reply MUST be in English.
- You MAY include at most ONE short Gulf Arabic empathy line in parentheses on the FIRST turn only — never on later turns.
- No other Arabic anywhere.
PROMPT;
        }

        return <<<PROMPT
=== STRICT LANGUAGE RULE ===
The user prefers English and has DISABLED the Arabic mirror.
- Respond ONLY in English.
- ZERO Arabic characters anywhere in your reply — not in parentheses, not as a mirror, not as examples, not at all.
- Ignore any earlier instruction that tells you to include Arabic mirror lines, Arabic empathy lines, or Gulf phrases. Those instructions are overridden.
PROMPT;
    }

    /**
     * Generate a response using the LLM.
     */
    protected function generateResponse(
        Session $session,
        string $message,
        string $systemPrompt,
        string $statePrompt,
    ): string {
        $messages = $session->getHistoryForLLM();

        // Add state-specific context, then a final identity anchor that always lands last
        // in the system prompt. Prevents the coaching script (which dominates the body of
        // the prompt) from overriding meta-question handling via recency bias.
        $fullSystemPrompt = $systemPrompt . "\n\n" . $statePrompt . "\n\n" . $this->getIdentityAnchor($session);

        $response = $this->llm->chat($messages, $fullSystemPrompt, [
            'temperature' => $this->getTemperatureForState($session->state),
            'max_tokens' => 150,
        ]);

        if (!$response->success) {
            // Log the error for debugging (only in Laravel context)
            if (function_exists('app') && app()->bound('log')) {
                app('log')->error('Sisly LLM call failed', [
                    'error' => $response->error,
                    'session_id' => $session->id,
                    'state' => $session->state->value,
                    'provider' => $this->llm->getName(),
                ]);
            }
            return $this->getFallbackResponse($session->state);
        }

        return $this->cleanResponse($response->content);
    }

    /**
     * Get temperature setting for a state.
     */
    protected function getTemperatureForState(SessionState $state): float
    {
        return match ($state) {
            SessionState::CRISIS_INTERVENTION => 0.0, // Deterministic for safety
            SessionState::INTAKE => 0.7,
            SessionState::EXPLORATION => 0.7,
            SessionState::DEEPENING => 0.6,
            SessionState::PROBLEM_SOLVING => 0.5,
            SessionState::CLOSING => 0.6,
            default => 0.7,
        };
    }

    /**
     * Get a fallback response when LLM fails.
     */
    protected function getFallbackResponse(SessionState $state): string
    {
        return match ($state) {
            SessionState::INTAKE => "I'm here with you. Tell me what's on your mind.",
            SessionState::EXPLORATION => "Can you tell me a bit more about what you're experiencing?",
            SessionState::DEEPENING => "I hear you. That makes sense.",
            SessionState::PROBLEM_SOLVING => "Let's try something together. Do you have 30 seconds, 1 minute, or 2 minutes?",
            SessionState::CLOSING => "You've done well to take this time for yourself.",
            default => "I'm here with you.",
        };
    }

    /**
     * Clean up the LLM response.
     */
    protected function cleanResponse(string $response): string
    {
        // Remove any markdown formatting
        $response = preg_replace('/^#+\s*/m', '', $response) ?? $response;

        // Remove bullet points
        $response = preg_replace('/^[-*]\s*/m', '', $response) ?? $response;

        // Trim whitespace
        $response = trim($response);

        // Limit length (20-25 words is the guideline)
        $words = explode(' ', $response);
        if (count($words) > 40) {
            $response = implode(' ', array_slice($words, 0, 40));
        }

        return $response;
    }

    /**
     * Check if this coach can handle the given message based on triggers.
     */
    public function canHandle(string $message): bool
    {
        $messageLower = mb_strtolower($message, 'UTF-8');
        $triggers = $this->getTriggers();

        $matchCount = 0;
        foreach ($triggers as $trigger) {
            if (str_contains($messageLower, strtolower($trigger))) {
                $matchCount++;
            }
        }

        // Require at least one trigger match
        return $matchCount >= 1;
    }

    /**
     * Get a randomly selected greeting in the specified language.
     *
     * Picks from the pre-written greeting pairs returned by getGreetings().
     *
     * @param string $language The preferred language ('en' or 'ar')
     * @return string The greeting message
     */
    public function getGreeting(string $language = 'en'): string
    {
        $greetings = $this->getGreetings();
        $selected = $greetings[array_rand($greetings)];

        return $selected[$language] ?? $selected['en'];
    }

    /**
     * Get all available greeting pairs for this coach.
     *
     * Each subclass must return an array of bilingual greeting pairs.
     *
     * @return array<array{en: string, ar: string}>
     */
    abstract public function getGreetings(): array;
}
