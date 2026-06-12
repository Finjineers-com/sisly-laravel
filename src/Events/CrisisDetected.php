<?php

declare(strict_types=1);

namespace Sisly\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Sisly\Enums\CrisisCategory;
use Sisly\Enums\CrisisSeverity;

/**
 * Event dispatched when a crisis is detected in user input.
 *
 * Consumers should listen for this event to:
 * - Log crisis events for safety monitoring
 * - Alert support staff if needed
 * - Track crisis patterns for quality improvement
 */
class CrisisDetected
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param array<string> $keywords
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly CrisisSeverity $severity,
        public readonly CrisisCategory $category,
        public readonly array $keywords,
        public readonly string $country,
        public readonly bool $resourcesProvided,
    ) {}

    /**
     * Create from crisis detection result.
     *
     * @param array<string> $keywords
     */
    public static function fromDetection(
        string $sessionId,
        CrisisSeverity $severity,
        CrisisCategory $category,
        array $keywords,
        string $country,
        bool $resourcesProvided = true,
    ): self {
        return new self(
            sessionId: $sessionId,
            severity: $severity,
            category: $category,
            keywords: $keywords,
            country: $country,
            resourcesProvided: $resourcesProvided,
        );
    }

    /**
     * Check if this is a critical severity crisis.
     */
    public function isCritical(): bool
    {
        return $this->severity === CrisisSeverity::CRITICAL;
    }

    /**
     * Get a summary string for logging.
     */
    public function getSummary(): string
    {
        return sprintf(
            'Crisis detected [%s/%s] in session %s (country: %s, keywords: %d)',
            $this->severity->value,
            $this->category->value,
            substr($this->sessionId, 0, 8) . '...',
            $this->country,
            count($this->keywords)
        );
    }
}
