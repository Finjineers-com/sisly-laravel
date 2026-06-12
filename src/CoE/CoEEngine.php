<?php

declare(strict_types=1);

namespace Sisly\CoE;

use Sisly\Contracts\LLMProviderInterface;
use Sisly\DTOs\CoETrace;
use Sisly\DTOs\Session;
use Sisly\Enums\SessionState;

/**
 * Chain of Empathy (CoE) Engine.
 *
 * Performs 5-step reasoning before generating a coaching response:
 * 1. Emotion - What is the user feeling? (primary + secondary)
 * 2. Cause - What triggered this? (situational/cognitive/relational)
 * 3. Intent - What do they need? (validation/venting/problem-solving)
 * 4. Strategy - What approach fits? (validation/exploration/reframe/technique)
 * 5. Response - Craft response aligned with strategy
 */
class CoEEngine
{
    /**
     * @var array<string> Valid user intents
     */
    private const VALID_INTENTS = ['validation', 'venting', 'advice', 'understanding', 'problem-solving'];

    /**
     * @var array<string> Valid response strategies
     */
    private const VALID_STRATEGIES = ['validation', 'exploration', 'reframe', 'technique', 'grounding', 'containment'];

    public function __construct(
        private readonly LLMProviderInterface $llm,
    ) {}

    /**
     * Run Chain of Empathy reasoning on the user message.
     */
    public function reason(Session $session, string $message, string $systemPrompt): CoETrace
    {
        $prompt = $this->buildCoEPrompt($session, $message, $systemPrompt);

        $response = $this->llm->generate($prompt, [
            'temperature' => 0.3, // Lower temperature for more consistent reasoning
            'max_tokens' => 300,
        ]);

        if (!$response->success) {
            return $this->getFallbackTrace($message);
        }

        return $this->parseCoEResponse($response->content, $message);
    }

    /**
     * Build the CoE reasoning prompt.
     */
    private function buildCoEPrompt(Session $session, string $message, string $systemPrompt): string
    {
        $stateContext = $this->getStateContext($session->state);
        $historyContext = $this->getHistoryContext($session);

        return <<<PROMPT
You are performing Chain of Empathy (CoE) analysis on a user message.

Context:
- Session state: {$session->state->value}
- Turn count: {$session->turnCount}
{$stateContext}
{$historyContext}

User message: "{$message}"

Analyze this message using the 5-step CoE framework:

1. EMOTION: What is the user feeling?
   - Primary emotion (dominant feeling)
   - Secondary emotion (if present)

2. CAUSE: What triggered this?
   - Situational (external event)
   - Cognitive (thought pattern)
   - Relational (interpersonal)

3. INTENT: What does the user need?
   - validation (feeling heard)
   - venting (releasing pressure)
   - advice (concrete guidance)
   - understanding (making sense)
   - problem-solving (action steps)

4. STRATEGY: What approach fits best?
   - validation (acknowledge and normalize)
   - exploration (ask clarifying question)
   - reframe (shift perspective)
   - technique (offer intervention)
   - grounding (body-focused)
   - containment (boundary setting)

5. RESPONSE: Draft a response (20-25 words max)

Output as JSON:
{
  "emotion_primary": "anxiety",
  "emotion_secondary": "frustration" or null,
  "cause_analysis": "Brief analysis of trigger",
  "user_intent": "validation|venting|advice|understanding|problem-solving",
  "strategy_selected": "validation|exploration|reframe|technique|grounding|containment",
  "draft_response": "Your drafted response here"
}

Respond with JSON only.
PROMPT;
    }

    /**
     * Get state-specific context for the prompt.
     */
    private function getStateContext(SessionState $state): string
    {
        return match ($state) {
            SessionState::INTAKE => "- Phase: Initial contact, focus on emotional mirroring",
            SessionState::EXPLORATION => "- Phase: Understanding the issue, max 2 questions",
            SessionState::DEEPENING => "- Phase: Summarize pattern, offer time choice",
            SessionState::PROBLEM_SOLVING => "- Phase: Deliver technique based on time choice",
            SessionState::CLOSING => "- Phase: Brief check-in, anchor gains, end cleanly",
            SessionState::CRISIS_INTERVENTION => "- Phase: SAFETY FIRST, acknowledge pain, stay present",
            default => "",
        };
    }

    /**
     * Get conversation history context.
     */
    private function getHistoryContext(Session $session): string
    {
        $history = $session->getHistoryForLLM();

        if (empty($history)) {
            return "- Conversation: First message";
        }

        $messageCount = count($history);
        $lastMessages = array_slice($history, -2);
        $summary = [];

        foreach ($lastMessages as $msg) {
            $role = ucfirst($msg['role']);
            $content = mb_substr($msg['content'], 0, 50) . (mb_strlen($msg['content']) > 50 ? '...' : '');
            $summary[] = "{$role}: {$content}";
        }

        return "- Conversation: {$messageCount} messages\n- Recent:\n  " . implode("\n  ", $summary);
    }

    /**
     * Parse the CoE response from the LLM.
     */
    private function parseCoEResponse(string $content, string $originalMessage): CoETrace
    {
        // Try to extract JSON from the response
        $content = trim($content);

        // Handle markdown code blocks
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $content, $matches)) {
            $content = $matches[1];
        }

        // Try to find JSON object
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $content = $matches[0];
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return $this->getFallbackTrace($originalMessage);
        }

        // Validate required fields
        if (!isset($data['emotion_primary']) || !isset($data['cause_analysis']) ||
            !isset($data['user_intent']) || !isset($data['strategy_selected']) ||
            !isset($data['draft_response'])) {
            return $this->getFallbackTrace($originalMessage);
        }

        // Validate intent
        if (!in_array($data['user_intent'], self::VALID_INTENTS, true)) {
            $data['user_intent'] = 'validation';
        }

        // Validate strategy
        if (!in_array($data['strategy_selected'], self::VALID_STRATEGIES, true)) {
            $data['strategy_selected'] = 'validation';
        }

        return CoETrace::fromArray($data);
    }

    /**
     * Get a fallback trace when LLM fails or response is unparseable.
     */
    private function getFallbackTrace(string $message): CoETrace
    {
        $emotion = $this->detectBasicEmotion($message);

        return new CoETrace(
            emotionPrimary: $emotion,
            emotionSecondary: null,
            causeAnalysis: 'Unable to analyze - using default',
            userIntent: 'validation',
            strategySelected: 'validation',
            draftResponse: "I hear you. Can you tell me a bit more about what you're experiencing?",
        );
    }

    /**
     * Basic keyword-based emotion detection for fallback.
     */
    private function detectBasicEmotion(string $message): string
    {
        $messageLower = mb_strtolower($message, 'UTF-8');

        $emotionKeywords = [
            'anxiety' => ['anxious', 'nervous', 'worried', 'scared', 'afraid', 'panic', 'meeting', 'presentation'],
            'anger' => ['angry', 'furious', 'frustrated', 'annoyed', 'mad', 'upset'],
            'sadness' => ['sad', 'down', 'depressed', 'hopeless', 'empty'],
            'overwhelm' => ['overwhelmed', 'drowning', 'too much', 'can\'t cope', 'pressure'],
            'doubt' => ['doubt', 'imposter', 'not good enough', 'failure', 'fake'],
        ];

        foreach ($emotionKeywords as $emotion => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($messageLower, $keyword)) {
                    return $emotion;
                }
            }
        }

        return 'distress'; // Default emotion
    }

    /**
     * Analyze if a response aligns with the CoE reasoning.
     */
    public function validateAlignment(CoETrace $trace, string $response): bool
    {
        // Simple alignment check - response should match the strategy
        $responseLower = mb_strtolower($response, 'UTF-8');

        return match ($trace->strategySelected) {
            'validation' => !str_contains($responseLower, '?') || str_contains($responseLower, 'hear') || str_contains($responseLower, 'understand'),
            'exploration' => str_contains($responseLower, '?'),
            'technique' => str_contains($responseLower, 'breath') || str_contains($responseLower, 'second') || str_contains($responseLower, 'minute'),
            default => true,
        };
    }
}
