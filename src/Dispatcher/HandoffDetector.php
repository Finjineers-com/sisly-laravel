<?php

declare(strict_types=1);

namespace Sisly\Dispatcher;

use Sisly\DTOs\Session;
use Sisly\Enums\CoachId;

/**
 * Detects when a session should be handed off to a different coach.
 *
 * Monitors conversation for signals that another coach would be more appropriate.
 */
class HandoffDetector
{
    /**
     * Keyword triggers for each coach.
     *
     * @var array<string, array<string>>
     */
    private array $coachTriggers;

    /**
     * @param array<string, mixed> $config Configuration options
     */
    public function __construct(array $config = [])
    {
        $this->coachTriggers = $config['triggers'] ?? $this->getDefaultTriggers();
    }

    /**
     * Analyze a message for potential handoff signals.
     */
    public function analyze(string $message, Session $session): HandoffResult
    {
        $currentCoach = $session->coachId;
        $messageLower = mb_strtolower($message, 'UTF-8');

        // Check each coach's triggers
        foreach ($this->coachTriggers as $coachId => $triggers) {
            // Skip current coach
            if ($coachId === $currentCoach->value) {
                continue;
            }

            $matchCount = 0;
            $matchedTriggers = [];

            foreach ($triggers as $trigger) {
                if (str_contains($messageLower, strtolower($trigger))) {
                    $matchCount++;
                    $matchedTriggers[] = $trigger;
                }
            }

            // Require at least 2 trigger matches for handoff suggestion
            if ($matchCount >= 2) {
                return HandoffResult::suggested(
                    suggestedCoach: CoachId::from($coachId),
                    confidence: min(0.5 + ($matchCount * 0.15), 0.95),
                    triggers: $matchedTriggers,
                );
            }
        }

        return HandoffResult::none();
    }

    /**
     * Check if the current conversation topic has drifted significantly.
     */
    public function detectTopicDrift(Session $session): bool
    {
        // Get last 4 user messages
        $userMessages = [];
        foreach (array_reverse($session->history) as $turn) {
            if ($turn->isUser()) {
                $userMessages[] = $turn->content;
                if (count($userMessages) >= 4) {
                    break;
                }
            }
        }

        if (count($userMessages) < 3) {
            return false;
        }

        // Simple drift detection: check if recent messages contain very different keywords
        // than the initial messages
        $initialKeywords = $this->extractKeywords($userMessages[count($userMessages) - 1] ?? '');
        $recentKeywords = $this->extractKeywords($userMessages[0] ?? '');

        $overlap = array_intersect($initialKeywords, $recentKeywords);

        // If less than 20% keyword overlap, consider it drift
        return count($overlap) < (count($initialKeywords) * 0.2);
    }

    /**
     * Extract significant keywords from a message.
     *
     * @return array<string>
     */
    private function extractKeywords(string $message): array
    {
        $stopWords = ['i', 'me', 'my', 'the', 'a', 'an', 'is', 'are', 'am', 'was', 'were', 'be',
            'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
            'could', 'should', 'may', 'might', 'must', 'can', 'to', 'of', 'in', 'for',
            'on', 'with', 'at', 'by', 'from', 'it', 'that', 'this', 'what', 'which',
            'who', 'whom', 'how', 'why', 'when', 'where', 'just', 'really', 'very',
            'so', 'but', 'and', 'or', 'if', 'then', 'because', 'as', 'about'];

        $words = preg_split('/\s+/', strtolower($message));
        if ($words === false) {
            return [];
        }

        return array_values(array_filter(
            $words,
            fn (string $word) => strlen($word) > 3 && !in_array($word, $stopWords, true)
        ));
    }

    /**
     * Get default trigger keywords for each coach.
     *
     * @return array<string, array<string>>
     */
    private function getDefaultTriggers(): array
    {
        return [
            CoachId::MEETLY->value => [
                'meeting', 'presentation', 'present', 'conference', 'speak',
                'speaking', 'audience', 'stage', 'pitch', 'interview',
                'performance', 'prepare', 'preparation',
            ],
            CoachId::VENTO->value => [
                'angry', 'anger', 'furious', 'frustrated', 'frustration',
                'mad', 'rage', 'irritated', 'annoyed', 'vent',
                'scream', 'hate', 'unfair', 'resentment',
            ],
            CoachId::LOOPY->value => [
                'thinking', 'thought', 'loop', 'stuck', 'ruminate',
                'rumination', 'overthink', 'overthinking', 'replay',
                'circle', 'same thought', 'can\'t stop thinking',
            ],
            CoachId::PRESSO->value => [
                'overwhelm', 'overwhelmed', 'pressure', 'too much',
                'deadline', 'urgent', 'stress', 'overload', 'swamped',
                'drowning', 'chaos', 'everything at once',
            ],
            CoachId::BOOSTLY->value => [
                'doubt', 'imposter', 'confidence', 'not good enough',
                'failure', 'fail', 'fake', 'fraud', 'capable',
                'compare', 'comparison', 'inferior', 'worthless',
            ],
        ];
    }

    /**
     * Add custom triggers for a coach.
     *
     * @param array<string> $triggers
     */
    public function addTriggers(CoachId $coach, array $triggers): void
    {
        if (!isset($this->coachTriggers[$coach->value])) {
            $this->coachTriggers[$coach->value] = [];
        }

        $this->coachTriggers[$coach->value] = array_merge(
            $this->coachTriggers[$coach->value],
            $triggers
        );
    }
}

/**
 * Result of handoff detection.
 */
class HandoffResult
{
    /**
     * @param array<string> $triggers
     */
    public function __construct(
        public readonly bool $suggested,
        public readonly ?CoachId $suggestedCoach = null,
        public readonly float $confidence = 0.0,
        public readonly array $triggers = [],
    ) {}

    /**
     * Create a result suggesting a handoff.
     *
     * @param array<string> $triggers
     */
    public static function suggested(CoachId $suggestedCoach, float $confidence, array $triggers = []): self
    {
        return new self(
            suggested: true,
            suggestedCoach: $suggestedCoach,
            confidence: $confidence,
            triggers: $triggers,
        );
    }

    /**
     * Create a result with no handoff suggested.
     */
    public static function none(): self
    {
        return new self(suggested: false);
    }

    /**
     * Check if handoff confidence meets threshold.
     */
    public function meetsThreshold(float $threshold = 0.7): bool
    {
        return $this->suggested && $this->confidence >= $threshold;
    }
}
