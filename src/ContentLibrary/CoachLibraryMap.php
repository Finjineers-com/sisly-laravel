<?php

declare(strict_types=1);

namespace Sisly\ContentLibrary;

final class CoachLibraryMap
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'meetly' => 'Meetings',
            'presso' => 'Too much',
            'loopy' => 'Quiet mind',
            'vento' => 'Let it out',
            'boostly' => 'Confidence',
            'safeo' => 'Meetings',
        ];
    }

    public static function libraryTypeForCoach(string $coachId): string
    {
        $key = strtolower(trim($coachId));

        return self::all()[$key] ?? 'Meetings';
    }
}
