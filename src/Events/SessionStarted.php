<?php

declare(strict_types=1);

namespace Sisly\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sisly\Enums\CoachId;

/**
 * Event dispatched when a new session is started.
 */
class SessionStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $sessionId,
        public readonly CoachId $coachId,
        public readonly string $country,
        public readonly string $language,
        public readonly \DateTimeImmutable $timestamp,
    ) {}

    /**
     * Create from session start data.
     */
    public static function fromSession(
        string $sessionId,
        CoachId $coachId,
        string $country,
        string $language = 'en',
    ): self {
        return new self(
            sessionId: $sessionId,
            coachId: $coachId,
            country: $country,
            language: $language,
            timestamp: new \DateTimeImmutable(),
        );
    }
}
