<?php

declare(strict_types=1);

namespace Sisly\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sisly\DTOs\CoETrace;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * Event dispatched when a coach response is generated.
 */
class ResponseGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $sessionId,
        public readonly string $response,
        public readonly ?string $arabicMirror,
        public readonly CoachId $coachId,
        public readonly SessionState $state,
        public readonly int $turnCount,
        public readonly ?CoETrace $coeTrace,
        public readonly int $responseTimeMs,
        public readonly \DateTimeImmutable $timestamp,
    ) {}

    /**
     * Create from response data.
     */
    public static function create(
        string $sessionId,
        string $response,
        ?string $arabicMirror,
        CoachId $coachId,
        SessionState $state,
        int $turnCount,
        ?CoETrace $coeTrace = null,
        int $responseTimeMs = 0,
    ): self {
        return new self(
            sessionId: $sessionId,
            response: $response,
            arabicMirror: $arabicMirror,
            coachId: $coachId,
            state: $state,
            turnCount: $turnCount,
            coeTrace: $coeTrace,
            responseTimeMs: $responseTimeMs,
            timestamp: new \DateTimeImmutable(),
        );
    }
}
