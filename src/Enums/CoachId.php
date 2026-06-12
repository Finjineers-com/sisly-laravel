<?php

declare(strict_types=1);

namespace Sisly\Enums;

enum CoachId: string
{
    case MEETLY = 'meetly';
    case VENTO = 'vento';
    case LOOPY = 'loopy';
    case PRESSO = 'presso';
    case BOOSTLY = 'boostly';
    case SAFEO = 'safeo';

    /**
     * Get the coach's display name.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::MEETLY => 'Meetly',
            self::VENTO => 'Vento',
            self::LOOPY => 'Loopy',
            self::PRESSO => 'Presso',
            self::BOOSTLY => 'Boostly',
            self::SAFEO => 'Safeo',
        };
    }

    /**
     * Get the coach's focus area description.
     */
    public function focus(): string
    {
        return match ($this) {
            self::MEETLY => 'Meeting and presentation anxiety',
            self::VENTO => 'Anger release and venting',
            self::LOOPY => 'Rumination and thought loops',
            self::PRESSO => 'Overload and urgency',
            self::BOOSTLY => 'Self-doubt and imposter feelings',
            self::SAFEO => 'Uncertainty, regional tension, and big decisions under pressure',
        };
    }

    /**
     * Get all coach IDs as an array of strings.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
