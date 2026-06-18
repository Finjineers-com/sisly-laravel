<?php

declare(strict_types=1);

namespace Sisly\Coaches;

use Sisly\CoE\CoEEngine;
use Sisly\Contracts\CoachInterface;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\DTOs\CoETrace;
use Sisly\DTOs\Prescription;
use Sisly\DTOs\Session;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * Base class for all Sisly coaches.
 *
 * Key changes from previous version:
 * - buildSystemPrompt() now composes SHARED_SPINE + PERSONA (from spec)
 * - Prescription (```sisly block) is parsed from coach responses
 * - Single-language mode is enforced (no arabic mirror for coaching)
 * - Identity/credential short-circuit paths remain unchanged
 * - FSM turn-counting bug (#1 from PENDING_FIXES) is addressed at
 *   SislyManager level; BaseCoach stays clean.
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
     * Short, per-language role description used in the hardcoded identity reply.
     */
    abstract public function getRoleDescription(string $language): string;

    /**
     * Get all available greeting pairs (bilingual) for initSession().
     *
     * @return array<array{en: string, ar: string}>
     */
    abstract public function getGreetings(): array;

    /**
     * Process a user message and generate a response.
     *
     * Returns: response text, optional arabic mirror (always null for coaching),
     * optional CoE trace, and optional Prescription (if handoff turn).
     *
     * @return array{response: string, arabic_mirror: ?string, coe_trace: ?CoETrace, prescription: ?Prescription}
     */
    public function process(Session $session, string $message): array
    {
        // Credential questions bypass the LLM — deterministic disclaimer
        if ($this->credentialDetector->isCredentialQuestion($message)) {
            return [
                'response'     => $this->buildHardcodedCredentialReply($session),
                'arabic_mirror' => null,
                'coe_trace'    => null,
                'prescription' => null,
            ];
        }

        // Identity questions bypass the LLM — deterministic name+role reply
        if ($this->identityDetector->isIdentityQuestion($message)) {
            return [
                'response'     => $this->buildHardcodedIdentityReply($session),
                'arabic_mirror' => null,
                'coe_trace'    => null,
                'prescription' => null,
            ];
        }

        // Build full system prompt (global rules + coach persona + transition bridge)
        $systemPrompt = $this->buildFullSystemPrompt($session);
        $statePrompt  = $this->getStatePrompt($session->state);

        // Run Chain of Empathy reasoning (internal, informs response)
        $coeResult = $this->coeEngine->reason($session, $message, $systemPrompt);

        // Generate response using LLM
        $rawResponse = $this->generateResponse($session, $message, $systemPrompt, $statePrompt);

        // Parse prescription block (```sisly) if present
        $prescription = Prescription::fromSislyBlock($rawResponse);
        $cleanResponse = $prescription !== null
            ? Prescription::stripBlock($rawResponse)
            : $rawResponse;

        return [
            'response'     => $cleanResponse,
            'arabic_mirror' => null, // Single-language mode: no mirror
            'coe_trace'    => $session->preferences->includeCoETrace ? $coeResult : null,
            'prescription' => $prescription,
        ];
    }

    /**
     * Build the deterministic identity reply.
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
     * Build the deterministic credential/human-ness reply.
     *
     * Disclaims any clinical credential and humanity claim.
     * Required by NIST-AI-RMF / NHS-DCB0129 posture.
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
     * Build the full system prompt: global rules + coach persona + transition bridge.
     *
     * Composition order (per spec):
     *   SHARED_SPINE (global/rules.md) + PERSONA (coaches/{id}/system.md) + bridge
     */
    protected function buildFullSystemPrompt(Session $session): string
    {
        $globalRules = $this->promptLoader->loadGlobal('rules');
        $coachSystem = $this->getSystemPrompt($session->state);
        $bridge      = $this->buildTransitionBridge($session);

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
     * Resolve the transition bridge for this turn (fires once after a transition).
     */
    protected function buildTransitionBridge(Session $session): string
    {
        if ($session->lastTransitionFromState === null) {
            return '';
        }

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
     * Wins recency bias over any conflicting instructions in the body.
     */
    protected function getIdentityAnchor(Session $session): string
    {
        $name         = $this->getName();
        $languageRule = $this->buildLanguageRule($session);

        return <<<PROMPT
=== FINAL OVERRIDE (highest priority — read this last) ===

Your name is {$name}. Sisly is the platform, not your name.

You are an AI coach. You are NOT a psychologist, therapist, psychiatrist, doctor, counselor, clinician, or human being. NEVER claim to be any of these.

If the user's latest message is a direct question about who you are, your name, or what this is — in any language — your reply MUST:
- begin with your name "{$name}",
- give a one-line role description,
- NOT run the standard greet-and-explore script.

If the user asks whether you are a therapist, doctor, human, or AI — disclaim that you are an AI coach, not a clinician or human.

{$languageRule}
PROMPT;
    }

    /**
     * Build the strict language rule from session preferences.
     */
    protected function buildLanguageRule(Session $session): string
    {
        $language    = $session->preferences->language;
        $arabicMirror = $session->preferences->arabicMirror;

        if ($language === 'ar') {
            return <<<PROMPT
=== STRICT LANGUAGE RULE ===
The user prefers Arabic. Respond ONLY in Gulf Arabic (Khaleeji).
- Do NOT include any English text.
- EXCEPTION: Always write coach names (MEETLY, VENTO, LOOPY, PRESSO, BOOSTLY, SAFEO) in Latin script.
- Ignore any earlier instruction that suggests bilingual or English output.
PROMPT;
        }

        if ($arabicMirror) {
            return <<<PROMPT
=== STRICT LANGUAGE RULE ===
The user prefers English. Respond in English.
- The body of your reply MUST be in English.
- You MAY include at most ONE short Gulf Arabic empathy line in parentheses on the FIRST turn only — never on later turns.
PROMPT;
        }

        return <<<PROMPT
=== STRICT LANGUAGE RULE ===
The user prefers English and has DISABLED the Arabic mirror.
- Respond ONLY in English.
- ZERO Arabic characters anywhere in your reply.
- Ignore any earlier instruction to include Arabic lines.
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

        $fullSystemPrompt = $systemPrompt
            . "\n\n" . $statePrompt
            . "\n\n" . $this->getIdentityAnchor($session);

        $response = $this->llm->chat($messages, $fullSystemPrompt, [
            'temperature' => $this->getTemperatureForState($session->state),
            'max_tokens'  => 150,
        ]);

        if (!$response->success) {
            if (function_exists('app') && app()->bound('log')) {
                app('log')->error('Sisly LLM call failed', [
                    'error'      => $response->error,
                    'session_id' => $session->id,
                    'state'      => $session->state->value,
                    'provider'   => $this->llm->getName(),
                ]);
            }
            return $this->getFallbackResponse($session->state);
        }

        return $this->cleanResponse($response->content);
    }

    /**
     * Get temperature for a given state.
     */
    protected function getTemperatureForState(SessionState $state): float
    {
        return match ($state) {
            SessionState::CRISIS_INTERVENTION => 0.0,
            SessionState::INTAKE              => 0.7,
            SessionState::EXPLORATION         => 0.7,
            SessionState::DEEPENING           => 0.6,
            SessionState::PROBLEM_SOLVING     => 0.5,
            SessionState::CLOSING             => 0.6,
            default                           => 0.7,
        };
    }

    /**
     * Get a fallback response when LLM fails.
     */
    protected function getFallbackResponse(SessionState $state): string
    {
        return match ($state) {
            SessionState::INTAKE          => "I'm here with you. Tell me what's on your mind.",
            SessionState::EXPLORATION     => "Can you tell me a bit more about what you're experiencing?",
            SessionState::DEEPENING       => "I hear you. That makes sense.",
            SessionState::PROBLEM_SOLVING => "Let's try something together. Do you have 30 seconds, 1 minute, or 2 minutes?",
            SessionState::CLOSING         => "You've done well to take this time for yourself.",
            default                       => "I'm here with you.",
        };
    }

    /**
     * Clean up the LLM response (remove markdown, limit length).
     * Spec: "capped at 1-3 sentences."
     */
    protected function cleanResponse(string $response): string
    {
        // Remove markdown formatting
        $response = preg_replace('/^#+\s*/m', '', $response) ?? $response;
        $response = preg_replace('/^[-*]\s*/m', '', $response) ?? $response;

        // Remove any accidental sisly block (should have been parsed already)
        $response = Prescription::stripBlock($response);

        $response = trim($response);

        // Cap at ~40 words (spec: 20-25 words, technique up to 40)
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

        foreach ($this->getTriggers() as $trigger) {
            if (str_contains($messageLower, strtolower($trigger))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a randomly selected greeting in the specified language.
     *
     * Used by initSession() — the primed opening (Phase 1, no model call).
     */
    public function getGreeting(string $language = 'en'): string
    {
        $greetings = $this->getGreetings();
        $selected  = $greetings[array_rand($greetings)];

        return $selected[$language] ?? $selected['en'];
    }
}
