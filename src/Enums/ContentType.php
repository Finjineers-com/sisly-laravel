<?php

declare(strict_types=1);

namespace Sisly\Enums;

enum ContentType: string
{
    case MEETINGS = 'Meetings';
    case TOO_MUCH = 'Too much';
    case QUIET_MIND = 'Quiet mind';
    case LET_IT_OUT = 'Let it out';
    case CONFIDENCE = 'Confidence';

    /**
     * Get all content type values as an array of strings.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
