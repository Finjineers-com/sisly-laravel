<?php

declare(strict_types=1);

namespace Sisly\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * Event dispatched when a session ends.
 */
class SessionEnded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $sessionId,
        public readonly CoachId $coachId,
        public readonly SessionState $finalState,
        public readonly int $totalTurns,
        public readonly bool $crisisOccurred,
        public readonly string $endReason,
        public readonly \DateTimeImmutable $startedAt,
        public readonly \DateTimeImmutable $endedAt,
    ) {}

    /**
     * Create from session end data.
     */
    public static function fromSession(
        string $sessionId,
        CoachId $coachId,
        SessionState $finalState,
        int $totalTurns,
        bool $crisisOccurred,
        string $endReason,
        \DateTimeImmutable $startedAt,
    ): self {
        return new self(
            sessionId: $sessionId,
            coachId: $coachId,
            finalState: $finalState,
            totalTurns: $totalTurns,
            crisisOccurred: $crisisOccurred,
            endReason: $endReason,
            startedAt: $startedAt,
            endedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Get the session duration in seconds.
     */
    public function getDurationSeconds(): int
    {
        return $this->endedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }

    /**
     * Check if the session ended naturally (reached CLOSING state).
     */
    public function wasNaturalEnd(): bool
    {
        return $this->finalState === SessionState::CLOSING;
    }

    /**
     * Check if the session ended due to turn limit.
     */
    public function wasTurnLimitEnd(): bool
    {
        return $this->endReason === 'turn_limit';
    }
}
