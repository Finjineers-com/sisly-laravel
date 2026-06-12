<?php

declare(strict_types=1);

namespace Sisly\Enums;

enum DependencyFlag: string
{
    case YELLOW = 'yellow';
    case RED = 'red';

    /**
     * Check if this flag indicates concerning dependency patterns.
     */
    public function isConcerning(): bool
    {
        return $this === self::RED;
    }

    /**
     * Get human-readable description.
     */
    public function description(): string
    {
        return match ($this) {
            self::YELLOW => 'Moderate dependency pattern detected',
            self::RED => 'High dependency pattern - recommend professional support',
        };
    }
}
