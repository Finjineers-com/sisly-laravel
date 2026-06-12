<?php

declare(strict_types=1);

namespace Sisly\Enums;

enum CrisisSeverity: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';

    /**
     * Check if this severity requires immediate resource provision.
     */
    public function requiresImmediateResources(): bool
    {
        return true; // Both levels require immediate resources
    }

    /**
     * Get the priority order (lower = more urgent).
     */
    public function priority(): int
    {
        return match ($this) {
            self::CRITICAL => 1,
            self::HIGH => 2,
        };
    }
}
