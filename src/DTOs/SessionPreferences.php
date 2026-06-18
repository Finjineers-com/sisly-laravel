<?php

declare(strict_types=1);

namespace Sisly\DTOs;

/**
 * Session configuration preferences.
 *
 * Semantic note on `arabicMirror` (PENDING_FIXES #10):
 *   Originally meant "include parallel Arabic translation in response."
 *   Since single-language mode (v1.1), it means "in EN responses, allow
 *   ONE short Arabic empathy line embedded in the body." Defaults to false
 *   (strict English-only) for new sessions. Will be renamed in v2.0.
 */
final class SessionPreferences
{
    public function __construct(
        public readonly string $language = 'en',         // 'en' | 'ar'

        /**
         * @deprecated since v1.2 — semantic changed.
         * In EN sessions: when true, allows ONE Gulf Arabic empathy line on turn 1.
         * Will be renamed `allowArabicEmpathyLine` in v2.0.
         * Defaults to false (strict English-only mode recommended).
         */
        public readonly bool $arabicMirror = false,

        public readonly bool $includeCoETrace = false,
    ) {}

    /**
     * @param array{language?: string, arabic_mirror?: bool, include_coe_trace?: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            language: $data['language'] ?? 'en',
            arabicMirror: $data['arabic_mirror'] ?? false,
            includeCoETrace: $data['include_coe_trace'] ?? false,
        );
    }

    /**
     * @return array{language: string, arabic_mirror: bool, include_coe_trace: bool}
     */
    public function toArray(): array
    {
        return [
            'language'         => $this->language,
            'arabic_mirror'    => $this->arabicMirror,
            'include_coe_trace' => $this->includeCoETrace,
        ];
    }
}
