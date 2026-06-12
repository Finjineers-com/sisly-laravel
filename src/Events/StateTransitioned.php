<?php

declare(strict_types=1);

namespace Sisly\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sisly\Enums\SessionState;

/**
 * Event dispatched when a session transitions to a new state.
 */
class StateTransitioned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $sessionId,
        public readonly SessionState $fromState,
        public readonly SessionState $toState,
        public readonly int $turnCount,
        public readonly \DateTimeImmutable $timestamp,
    ) {}

    /**
     * Create from a state transition.
     */
    public static function fromTransition(
        string $sessionId,
        SessionState $fromState,
        SessionState $toState,
        int $turnCount,
    ): self {
        return new self(
            sessionId: $sessionId,
            fromState: $fromState,
            toState: $toState,
            turnCount: $turnCount,
            timestamp: new \DateTimeImmutable(),
        );
    }

    /**
     * Check if this was a transition to crisis state.
     */
    public function isCrisisTransition(): bool
    {
        return $this->toState === SessionState::CRISIS_INTERVENTION;
    }

    /**
     * Check if this was a transition to terminal state.
     */
    public function isTerminalTransition(): bool
    {
        return $this->toState === SessionState::CLOSING;
    }
}
