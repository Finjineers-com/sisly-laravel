<?php

declare(strict_types=1);

namespace Sisly\Dispatcher;

use Sisly\Enums\CoachId;

/**
 * Result of dispatcher classification.
 */
class DispatcherResult
{
    public function __construct(
        public readonly CoachId $coach,
        public readonly float $confidence,
        public readonly string $reasoning,
        public readonly bool $success = true,
        public readonly ?string $error = null,
    ) {}

    /**
     * Create a successful dispatch result.
     */
    public static function success(CoachId $coach, float $confidence, string $reasoning): self
    {
        return new self(
            coach: $coach,
            confidence: $confidence,
            reasoning: $reasoning,
            success: true,
        );
    }

    /**
     * Create a failed dispatch result with fallback coach.
     */
    public static function failure(string $error, CoachId $fallbackCoach = CoachId::MEETLY): self
    {
        return new self(
            coach: $fallbackCoach,
            confidence: 0.0,
            reasoning: 'Fallback due to error: ' . $error,
            success: false,
            error: $error,
        );
    }

    /**
     * Check if the confidence meets a threshold.
     */
    public function meetsThreshold(float $threshold = 0.7): bool
    {
        return $this->confidence >= $threshold;
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'coach' => $this->coach->value,
            'confidence' => $this->confidence,
            'reasoning' => $this->reasoning,
            'success' => $this->success,
            'error' => $this->error,
        ];
    }
}
