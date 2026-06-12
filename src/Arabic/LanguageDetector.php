<?php

declare(strict_types=1);

namespace Sisly\Arabic;

/**
 * Detects the primary language of input text.
 * Supports English and Arabic (including Gulf dialect).
 */
class LanguageDetector
{
    /**
     * Arabic Unicode character ranges.
     * Covers Arabic script including extended characters.
     */
    private const ARABIC_RANGES = [
        [0x0600, 0x06FF], // Arabic
        [0x0750, 0x077F], // Arabic Supplement
        [0x08A0, 0x08FF], // Arabic Extended-A
        [0xFB50, 0xFDFF], // Arabic Presentation Forms-A
        [0xFE70, 0xFEFF], // Arabic Presentation Forms-B
    ];

    /**
     * Minimum ratio of Arabic characters to consider text as Arabic.
     */
    private float $arabicThreshold;

    public function __construct(float $arabicThreshold = 0.3)
    {
        $this->arabicThreshold = $arabicThreshold;
    }

    /**
     * Detect the primary language of the text.
     *
     * @return string 'ar' for Arabic, 'en' for English/other
     */
    public function detect(string $text): string
    {
        if (empty(trim($text))) {
            return 'en';
        }

        $arabicRatio = $this->getArabicRatio($text);

        return $arabicRatio >= $this->arabicThreshold ? 'ar' : 'en';
    }

    /**
     * Check if the text contains any Arabic characters.
     */
    public function containsArabic(string $text): bool
    {
        return $this->countArabicCharacters($text) > 0;
    }

    /**
     * Check if the text is primarily Arabic.
     */
    public function isPrimarilyArabic(string $text): bool
    {
        return $this->detect($text) === 'ar';
    }

    /**
     * Check if the text is primarily English (or non-Arabic).
     */
    public function isPrimarilyEnglish(string $text): bool
    {
        return $this->detect($text) === 'en';
    }

    /**
     * Get the ratio of Arabic characters to total alphabetic characters.
     */
    public function getArabicRatio(string $text): float
    {
        $arabicCount = $this->countArabicCharacters($text);
        $totalAlpha = $this->countAlphabeticCharacters($text);

        if ($totalAlpha === 0) {
            return 0.0;
        }

        return $arabicCount / $totalAlpha;
    }

    /**
     * Count the number of Arabic characters in the text.
     */
    public function countArabicCharacters(string $text): int
    {
        $count = 0;

        // Convert to array of Unicode code points
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($characters === false) {
            return 0;
        }

        foreach ($characters as $char) {
            if ($this->isArabicCharacter($char)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count total alphabetic characters (Arabic + Latin).
     */
    private function countAlphabeticCharacters(string $text): int
    {
        $count = 0;

        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($characters === false) {
            return 0;
        }

        foreach ($characters as $char) {
            if ($this->isArabicCharacter($char) || $this->isLatinLetter($char)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Check if a character is Arabic.
     */
    private function isArabicCharacter(string $char): bool
    {
        $codePoint = $this->getCodePoint($char);

        foreach (self::ARABIC_RANGES as [$start, $end]) {
            if ($codePoint >= $start && $codePoint <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a character is a Latin letter.
     */
    private function isLatinLetter(string $char): bool
    {
        return preg_match('/^[a-zA-Z]$/u', $char) === 1;
    }

    /**
     * Get the Unicode code point of a character.
     */
    private function getCodePoint(string $char): int
    {
        $code = mb_ord($char, 'UTF-8');

        return $code !== false ? $code : 0;
    }

    /**
     * Detect if text contains mixed languages.
     */
    public function isMixedLanguage(string $text): bool
    {
        $ratio = $this->getArabicRatio($text);

        // Mixed if neither predominantly Arabic nor English
        return $ratio > 0.1 && $ratio < 0.9;
    }

    /**
     * Get detailed language analysis.
     *
     * @return array{
     *     detected: string,
     *     arabic_ratio: float,
     *     arabic_count: int,
     *     is_mixed: bool,
     *     contains_arabic: bool
     * }
     */
    public function analyze(string $text): array
    {
        $arabicCount = $this->countArabicCharacters($text);
        $ratio = $this->getArabicRatio($text);

        return [
            'detected' => $this->detect($text),
            'arabic_ratio' => round($ratio, 3),
            'arabic_count' => $arabicCount,
            'is_mixed' => $this->isMixedLanguage($text),
            'contains_arabic' => $arabicCount > 0,
        ];
    }
}
