<?php

declare(strict_types=1);

namespace Sisly\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * Event dispatched when a user message is received.
 */
class MessageReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $sessionId,
        public readonly string $message,
        public readonly CoachId $coachId,
        public readonly SessionState $state,
        public readonly int $turnCount,
        public readonly \DateTimeImmutable $timestamp,
    ) {}

    /**
     * Create from message data.
     */
    public static function create(
        string $sessionId,
        string $message,
        CoachId $coachId,
        SessionState $state,
        int $turnCount,
    ): self {
        return new self(
            sessionId: $sessionId,
            message: $message,
            coachId: $coachId,
            state: $state,
            turnCount: $turnCount,
            timestamp: new \DateTimeImmutable(),
        );
    }
}
