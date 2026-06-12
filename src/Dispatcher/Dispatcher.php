<?php

declare(strict_types=1);

namespace Sisly\Dispatcher;

use Sisly\Contracts\LLMProviderInterface;
use Sisly\Enums\CoachId;

/**
 * Routes incoming messages to the appropriate coach.
 *
 * Uses LLM to classify user intent and determine the best coach.
 */
class Dispatcher
{
    /**
     * @var string The dispatcher system prompt
     */
    private string $dispatcherPrompt;

    /**
     * @var float Confidence threshold for accepting classification
     */
    private float $confidenceThreshold;

    /**
     * @var CoachId Default coach when classification fails or confidence is low
     */
    private CoachId $defaultCoach;

    /**
     * @var array<string> Enabled coaches
     */
    private array $enabledCoaches;

    /**
     * @param array<string, mixed> $config Dispatcher configuration
     */
    public function __construct(
        private readonly LLMProviderInterface $llm,
        array $config = [],
    ) {
        $this->dispatcherPrompt = $config['prompt'] ?? $this->getDefaultPrompt();
        $this->confidenceThreshold = $config['confidence_threshold'] ?? 0.7;
        $this->defaultCoach = isset($config['default_coach'])
            ? CoachId::from($config['default_coach'])
            : CoachId::MEETLY;
        $this->enabledCoaches = $config['enabled_coaches'] ?? CoachId::values();
    }

    /**
     * Classify a message and determine the appropriate coach.
     */
    public function classify(string $message): DispatcherResult
    {
        try {
            $prompt = $this->buildPrompt($message);
            $response = $this->llm->generate($prompt);

            if (!$response->success) {
                return DispatcherResult::failure(
                    $response->error ?? 'LLM request failed',
                    $this->defaultCoach
                );
            }

            return $this->parseResponse($response->content);
        } catch (\Throwable $e) {
            return DispatcherResult::failure($e->getMessage(), $this->defaultCoach);
        }
    }

    /**
     * Re-classify during an ongoing session (for potential handoff).
     */
    public function reclassify(string $message, CoachId $currentCoach): DispatcherResult
    {
        $result = $this->classify($message);

        // Only suggest handoff if confidence is high and different from current
        if ($result->success && $result->meetsThreshold() && $result->coach !== $currentCoach) {
            return $result;
        }

        // Stay with current coach
        return DispatcherResult::success(
            coach: $currentCoach,
            confidence: 1.0,
            reasoning: 'Staying with current coach',
        );
    }

    /**
     * Build the prompt for classification.
     */
    private function buildPrompt(string $message): string
    {
        $enabledList = implode(', ', $this->enabledCoaches);

        return str_replace(
            ['{{USER_MESSAGE}}', '{{ENABLED_COACHES}}'],
            [$message, $enabledList],
            $this->dispatcherPrompt
        );
    }

    /**
     * Parse the LLM response into a DispatcherResult.
     */
    private function parseResponse(string $response): DispatcherResult
    {
        // Try to parse as JSON
        $data = json_decode($response, true);

        if (!is_array($data)) {
            // Try to extract coach name from text response
            return $this->parseTextResponse($response);
        }

        $coachValue = $data['coach'] ?? null;
        $confidence = $data['confidence'] ?? 0.5;
        $reasoning = $data['reasoning'] ?? 'No reasoning provided';

        if ($coachValue === null) {
            return DispatcherResult::failure('No coach in response', $this->defaultCoach);
        }

        try {
            $coach = CoachId::from($coachValue);

            // Ensure coach is enabled
            if (!in_array($coach->value, $this->enabledCoaches, true)) {
                return DispatcherResult::success(
                    coach: $this->defaultCoach,
                    confidence: $confidence,
                    reasoning: "Coach {$coachValue} not enabled, using default",
                );
            }

            return DispatcherResult::success($coach, $confidence, $reasoning);
        } catch (\ValueError) {
            return DispatcherResult::failure("Invalid coach: {$coachValue}", $this->defaultCoach);
        }
    }

    /**
     * Parse a text response (fallback for non-JSON responses).
     */
    private function parseTextResponse(string $response): DispatcherResult
    {
        $responseLower = strtolower($response);

        foreach (CoachId::cases() as $coach) {
            if (str_contains($responseLower, strtolower($coach->value))) {
                if (in_array($coach->value, $this->enabledCoaches, true)) {
                    return DispatcherResult::success(
                        coach: $coach,
                        confidence: 0.6, // Lower confidence for text parsing
                        reasoning: 'Extracted from text response',
                    );
                }
            }
        }

        return DispatcherResult::success(
            coach: $this->defaultCoach,
            confidence: 0.5,
            reasoning: 'Could not parse response, using default coach',
        );
    }

    /**
     * Get the default dispatcher prompt.
     */
    private function getDefaultPrompt(): string
    {
        return <<<PROMPT
You are a dispatcher for an emotional coaching system. Analyze the user's message and determine which coach is most appropriate.

Available coaches:
- meetly: For meeting anxiety, presentation nervousness, pre/post meeting support
- vento: For anger, frustration, need to vent
- loopy: For rumination, thought loops, overthinking, stuck thoughts
- presso: For overwhelm, pressure, too many tasks, urgency
- boostly: For self-doubt, imposter syndrome, lack of confidence
- safeo: For uncertainty, regional tension, job insecurity, fear of the unknown, big decisions under pressure

User message: {{USER_MESSAGE}}

Enabled coaches: {{ENABLED_COACHES}}

Respond with JSON only:
{"coach": "<coach_id>", "confidence": <0.0-1.0>, "reasoning": "<brief explanation>"}
PROMPT;
    }

    /**
     * Get the current confidence threshold.
     */
    public function getConfidenceThreshold(): float
    {
        return $this->confidenceThreshold;
    }

    /**
     * Set a custom dispatcher prompt.
     */
    public function setPrompt(string $prompt): void
    {
        $this->dispatcherPrompt = $prompt;
    }
}
