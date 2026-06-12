<?php

declare(strict_types=1);

namespace Sisly\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when LLM failover occurs.
 *
 * This event is fired when the primary LLM provider fails
 * and the system switches to a backup provider.
 */
class LLMFailoverOccurred
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $previousProvider,
        public readonly string $newProvider,
        public readonly string $error,
        public readonly \DateTimeImmutable $timestamp,
    ) {}

    /**
     * Create from failover data.
     */
    public static function create(
        string $previousProvider,
        string $newProvider,
        string $error,
    ): self {
        return new self(
            previousProvider: $previousProvider,
            newProvider: $newProvider,
            error: $error,
            timestamp: new \DateTimeImmutable(),
        );
    }
}
