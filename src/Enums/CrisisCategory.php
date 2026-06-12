<?php

declare(strict_types=1);

namespace Sisly\Enums;

enum CrisisCategory: string
{
    case SELF_HARM = 'self_harm';
    case SUICIDE = 'suicide';
    case HARM_TO_OTHERS = 'harm_to_others';
    case ABUSE = 'abuse';
    case MEDICAL_EMERGENCY = 'medical_emergency';
    case PSYCHOSIS = 'psychosis';

    /**
     * Get human-readable label for the category.
     */
    public function label(): string
    {
        return match ($this) {
            self::SELF_HARM => 'Self Harm',
            self::SUICIDE => 'Suicide',
            self::HARM_TO_OTHERS => 'Harm to Others',
            self::ABUSE => 'Abuse',
            self::MEDICAL_EMERGENCY => 'Medical Emergency',
            self::PSYCHOSIS => 'Psychosis',
        };
    }

    /**
     * Get the default severity for this category.
     */
    public function defaultSeverity(): CrisisSeverity
    {
        return match ($this) {
            self::SUICIDE => CrisisSeverity::CRITICAL,
            self::HARM_TO_OTHERS => CrisisSeverity::CRITICAL,
            self::MEDICAL_EMERGENCY => CrisisSeverity::CRITICAL,
            self::SELF_HARM => CrisisSeverity::HIGH,
            self::ABUSE => CrisisSeverity::HIGH,
            self::PSYCHOSIS => CrisisSeverity::HIGH,
        };
    }
}
