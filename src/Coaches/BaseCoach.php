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
    protected PrescriptionParser $prescriptionParser;

    public function __construct(
        protected readonly LLMProviderInterface $llm,
        ?PromptLoader $promptLoader = null,
        ?CoEEngine $coeEngine = null,
        ?IdentityQuestionDetector $identityDetector = null,
        ?CredentialQuestionDetector $credentialDetector = null,
        ?PrescriptionParser $prescriptionParser = null,
    ) {
        $this->promptLoader = $promptLoader ?? new PromptLoader();
        $this->coeEngine = $coeEngine ?? new CoEEngine($llm);
        $this->identityDetector = $identityDetector ?? new IdentityQuestionDetector();
        $this->credentialDetector = $credentialDetector ?? new CredentialQuestionDetector();
        $this->prescriptionParser = $prescriptionParser ?? (function_exists('app') && app()->bound(PrescriptionParser::class) ? app(PrescriptionParser::class) : new PrescriptionParser());
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
        // Credential / human-ness questions bypass the LLM entirely.
        if ($this->credentialDetector->isCredentialQuestion($message)) {
            return [
                'response' => $this->buildHardcodedCredentialReply($session),
                'arabic_mirror' => null,
                'coe_trace' => null,
            ];
        }

        // Identity questions bypass the LLM entirely.
        if ($this->identityDetector->isIdentityQuestion($message)) {
            return [
                'response' => $this->buildHardcodedIdentityReply($session),
                'arabic_mirror' => null,
                'coe_trace' => null,
            ];
        }

        // Build the base system prompt (global rules + coach identity).
        $systemPrompt = $this->buildFullSystemPrompt($session);

        // Load the state-specific prompt (intake, exploration, deepening, etc.).
        // INTAKE uses its own dedicated prompt, not a duplicate of system.
        $statePrompt = $this->getStatePrompt($session->state);

        // Run Chain of Empathy reasoning. The result is injected into the
        // main LLM call so context-aware emotional analysis actually drives
        // the response instead of being discarded.
        $coeResult = $this->coeEngine->reason($session, $message, $systemPrompt);

        // Generate response using LLM, now with CoE analysis injected.
        $rawResponse = $this->generateResponse($session, $message, $systemPrompt, $statePrompt, $coeResult);

        $prescription = null;
        $cleanResponse = $rawResponse;

        // Parse prescription if enabled
        if ($this->getConfig('sisly.prescription.enabled', true)) {
            $parsed = $this->prescriptionParser->parse($rawResponse);
            $cleanResponse = $parsed['text'];
            $prescription = $parsed['prescription'];
        }

        $response = $this->cleanResponse($cleanResponse);

        return [
            'response' => $response,
            'arabic_mirror' => null, // Single-language mode: no mirror needed
            'coe_trace' => $session->preferences->includeCoETrace ? $coeResult : null,
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
     * Build the deterministic credential / human-ness reply.
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
     * Build a compact conversation summary for longer sessions.
     *
     * For sessions beyond 6 turns, prepend a running summary of the key
     * context gathered so far (emotion, issue, body sensations, technique
     * offered) so the LLM never loses track of what was said earlier even
     * when the raw history window is full.
     */
    protected function buildConversationSummary(Session $session): string
    {
        $history = $session->getHistoryForLLM();
        $turnCount = count($history);

        // Only inject a summary for longer conversations (> 6 turns = 3 exchanges)
        if ($turnCount <= 6) {
            return '';
        }

        // Collect key signals from the full history
        $userMessages = array_filter($history, fn($t) => $t['role'] === 'user');
        $assistantMessages = array_filter($history, fn($t) => $t['role'] === 'assistant');

        $firstUserMsg = array_values($userMessages)[0]['content'] ?? '';
        $recentUserMessages = array_slice(array_values($userMessages), -3);
        $recentUserText = implode(' | ', array_map(fn($m) => mb_substr($m['content'], 0, 80), $recentUserMessages));

        $stateLabel = $session->state->label();
        $coachName = $this->getName();
        $turnPairs = (int) floor($turnCount / 2);

        return <<<SUMMARY
## Active Session Context (turn {$turnPairs} of this conversation)

Coach: {$coachName} | Phase: {$stateLabel}

The user opened with: "{$firstUserMsg}"
Recent messages from user: "{$recentUserText}"

You have been in active conversation for {$turnPairs} exchanges. The full conversation history follows in the messages array. Use it to:
- Refer back to specific things the user already told you (their meeting, their fear, their body sensation)
- Build on what you have already explored — do NOT start over
- Advance the session toward its natural conclusion if the user has been well-heard
- Maintain your coach-specific scope and voice throughout

Do NOT re-introduce yourself. Do NOT ask questions the user already answered. Continue from where you are.
SUMMARY;
    }

    /**
     * Build the CoE context block to inject into the LLM system prompt.
     *
     * The Chain of Empathy analysis is the intelligence layer that should
     * directly shape the response. Without injection, it is computed but
     * completely ignored by the main LLM call — wasting both an API call
     * and the only context-aware signal available for this turn.
     */
    protected function buildCoeContextBlock(CoETrace $coeTrace): string
    {
        $emotionLine = $coeTrace->emotionSecondary
            ? "{$coeTrace->emotionPrimary} (secondary: {$coeTrace->emotionSecondary})"
            : $coeTrace->emotionPrimary;

        $strategyInstructions = $this->getStrategyInstructions($coeTrace->strategySelected, $coeTrace->userIntent);

        return <<<COE
## Chain of Empathy Analysis (this turn — use this to shape your response)

Emotion detected: {$emotionLine}
Cause/trigger: {$coeTrace->causeAnalysis}
What the user needs right now: {$coeTrace->userIntent}
Recommended strategy: {$coeTrace->strategySelected}

{$strategyInstructions}

Suggested response direction: "{$coeTrace->draftResponse}"
(You may use this draft as a starting point, but adapt it to the conversation history and your coach voice.)
COE;
    }

    /**
     * Translate a CoE strategy into concrete, turn-specific instructions.
     */
    protected function getStrategyInstructions(string $strategy, string $intent): string
    {
        return match ($strategy) {
            'validation' => "The user needs to feel heard first. Reflect their emotion back plainly without minimising. Do NOT offer a solution or technique yet. One sentence of acknowledgment, then one open question if needed.",
            'exploration' => "You need more context. Ask ONE clarifying question that helps you understand either: the specific trigger, the feared outcome, or where it shows up in their body. Do not ask more than one question.",
            'reframe' => "The user is ready for a perspective shift. Offer a gentle reframe in one sentence — something that normalises or redirects, without dismissing the feeling.",
            'technique' => "The user is ready for a practical tool. Offer a technique matched to their time availability and presenting issue. Guide step by step. Do not explain why it works.",
            'grounding' => "The user is escalating or dissociated. Ground them in physical present reality first: feet, hands, breath. Short, calm, directive sentences.",
            'containment' => "The user is at capacity or out of scope. Acknowledge the weight, then gently redirect to what you CAN help with, or suggest a handoff to the appropriate coach.",
            default => "Read the user's message carefully and respond with empathy before anything else.",
        };
    }

    /**
     * Build the scope enforcement block for this coach.
     *
     * Injects a per-turn reminder of what this coach handles and what
     * triggers a handoff. Prevents scope drift across long conversations
     * where the LLM's persona can gradually loosen from the coach's domain.
     */
    protected function buildScopeEnforcementBlock(Session $session): string
    {
        $name = $this->getName();
        $inScope = $this->getInScopeDescription();
        $outOfScope = $this->getOutOfScopeDescription();
        $handoffInstruction = $this->getHandoffInstruction();

        return <<<SCOPE
## Scope Enforcement for {$name} (applies every turn — non-negotiable)

YOU ARE {$name}. Your domain is: {$inScope}

OUT OF SCOPE for {$name}: {$outOfScope}

If the user's current message is about something outside your domain:
{$handoffInstruction}

Do NOT attempt to coach on out-of-scope topics. Do NOT give generic life advice, relationship advice, career advice, medical advice, or productivity tips. Stay strictly within your domain.

If the user is clearly within your domain but their needs have shifted to another domain mid-conversation, acknowledge what they've shared and offer a warm handoff.
SCOPE;
    }

    /**
     * Return a short in-scope description for this coach.
     * Subclasses override this for precise domain enforcement.
     */
    protected function getInScopeDescription(): string
    {
        return $this->getId()->focus();
    }

    /**
     * Return a short out-of-scope description for this coach.
     * Subclasses should override with coach-specific out-of-scope items.
     */
    protected function getOutOfScopeDescription(): string
    {
        return 'general life coaching, relationship advice, medical advice, career coaching, financial advice, legal advice, productivity systems';
    }

    /**
     * Return the handoff instruction when the user is out of scope.
     */
    protected function getHandoffInstruction(): string
    {
        return "Say: \"What you're describing sounds like it's outside what I can help with directly. [Name the right coach if applicable] would be better suited for this.\" Then offer to stay present if the user wants to come back to your domain.";
    }

    /**
     * Final identity + language anchor appended last in the system prompt.
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
     *
     * Now accepts CoETrace so the emotional analysis is injected into the
     * system prompt rather than being silently discarded. Also injects:
     * - Scope enforcement block (prevents domain drift across long sessions)
     * - Conversation summary (prevents context loss after turn 6)
     */
    protected function generateResponse(
        Session $session,
        string $message,
        string $systemPrompt,
        string $statePrompt,
        ?CoETrace $coeTrace = null,
    ): string {
        $messages = $session->getHistoryForLLM();

        // Build the full system prompt with all context layers:
        // 1. Base: global rules + coach identity
        // 2. State prompt: what to do in this FSM phase (intake/exploration/etc.)
        // 3. CoE block: emotion/intent/strategy for THIS turn (previously discarded)
        // 4. Scope enforcement: per-turn domain boundary reminder
        // 5. Conversation summary: prevents context loss in longer sessions
        // 6. Identity anchor: language rule + name override (must be last)
        $coeBlock = $coeTrace !== null ? "\n\n" . $this->buildCoeContextBlock($coeTrace) : '';
        $scopeBlock = "\n\n" . $this->buildScopeEnforcementBlock($session);
        $summaryBlock = $this->buildConversationSummary($session);
        $summarySection = $summaryBlock !== '' ? "\n\n" . $summaryBlock : '';

        $fullSystemPrompt = $systemPrompt
            . "\n\n" . $statePrompt
            . $coeBlock
            . $scopeBlock
            . $summarySection
            . "\n\n" . $this->getIdentityAnchor($session);

        // Max tokens: technique delivery needs more room (step-by-step instructions).
        // Other states use 200 tokens — enough for a meaningful response while
        // staying concise. The old 150-token cap was cutting sentences mid-thought.
        $maxTokens = 200;
        if ($this->getConfig('sisly.prescription.enabled', true) && $session->state === SessionState::PROBLEM_SOLVING) {
            $maxTokens = $this->getConfig('sisly.prescription.max_tokens_handoff', 400);
        }

        $response = $this->llm->chat($messages, $fullSystemPrompt, [
            'temperature' => $this->getTemperatureForState($session->state),
            'max_tokens' => $maxTokens,
        ]);

        if (!$response->success) {
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

        return $response->content;
    }

    /**
     * Get temperature setting for a state.
     */
    protected function getTemperatureForState(SessionState $state): float
    {
        return match ($state) {
            SessionState::CRISIS_INTERVENTION => 0.0,
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
        ];
    }

    /**
     * Clean up the LLM response.
     *
     * Removes markdown formatting and truncates at a sentence boundary
     * rather than a raw word count to avoid cut-off mid-sentence responses.
     */
    protected function cleanResponse(string $response): string
    {
        // Remove markdown headers
        $response = preg_replace('/^#+\s*/m', '', $response) ?? $response;

        // Remove bullet points
        $response = preg_replace('/^[-*]\s*/m', '', $response) ?? $response;

        // Trim whitespace
        $response = trim($response);

        // Truncate at sentence boundary, not raw word count.
        // Technique instructions (PROBLEM_SOLVING) need up to ~120 words.
        // Other states target 20-40 words. Hard cap at 120 words.
        $words = explode(' ', $response);
        if (count($words) > 120) {
            // Find the last sentence-ending punctuation within 120 words
            $truncated = implode(' ', array_slice($words, 0, 120));
            // Back off to the last sentence boundary
            $lastPunct = max(
                strrpos($truncated, '.'),
                strrpos($truncated, '?'),
                strrpos($truncated, '!'),
            );
            if ($lastPunct !== false && $lastPunct > strlen($truncated) * 0.5) {
                $response = substr($truncated, 0, $lastPunct + 1);
            } else {
                $response = $truncated;
            }
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

        return $matchCount >= 1;
    }

    /**
     * Get a randomly selected greeting in the specified language.
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
     * @return array<array{en: string, ar: string}>
     */
    abstract public function getGreetings(): array;

    /**
     * Get a configuration value safely without throwing container errors in unit tests.
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        if (function_exists('app') && app()->bound('config')) {
            return config($key, $default);
        }
        return $default;
    }
}
