<?php

declare(strict_types=1);

namespace Sisly\DTOs;

/**
 * Session configuration preferences.
 */
final class SessionPreferences
{
    public function __construct(
        public readonly string $language = 'en',
        public readonly bool $arabicMirror = true,
        public readonly bool $includeCoETrace = false,
    ) {}

    /**
     * Create instance from array.
     *
     * @param array{language?: string, arabic_mirror?: bool, include_coe_trace?: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            language: $data['language'] ?? 'en',
            arabicMirror: $data['arabic_mirror'] ?? true,
            includeCoETrace: $data['include_coe_trace'] ?? false,
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array{language: string, arabic_mirror: bool, include_coe_trace: bool}
     */
    public function toArray(): array
    {
        return [
            'language' => $this->language,
            'arabic_mirror' => $this->arabicMirror,
            'include_coe_trace' => $this->includeCoETrace,
        ];
    }
}
