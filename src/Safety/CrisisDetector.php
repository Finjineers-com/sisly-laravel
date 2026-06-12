<?php

declare(strict_types=1);

namespace Sisly\Safety;

use Sisly\DTOs\CrisisInfo;
use Sisly\Enums\CrisisCategory;
use Sisly\Enums\CrisisSeverity;

/**
 * Detects crisis situations in user messages using keyword matching.
 *
 * This is a safety-critical component that runs BEFORE any LLM calls.
 * It uses a deterministic keyword lexicon to ensure reliable detection.
 */
class CrisisDetector
{
    /**
     * @var array<string, array{severity: string, patterns: array<string, array<array{text: string, type: string}>>}>
     */
    private array $lexicon;

    /**
     * @param array<string, mixed>|null $lexicon Loaded lexicon data, or null to load from default path
     */
    public function __construct(?array $lexicon = null)
    {
        if ($lexicon === null) {
            $this->lexicon = $this->loadDefaultLexicon();
        } else {
            $this->lexicon = $lexicon['categories'] ?? $lexicon;
        }
    }

    /**
     * Check a message for crisis indicators.
     */
    public function check(string $message): CrisisInfo
    {
        $normalized = $this->normalize($message);
        $matches = [];

        foreach ($this->lexicon as $categoryKey => $categoryData) {
            $patterns = $categoryData['patterns'] ?? [];
            $severity = $categoryData['severity'] ?? 'high';

            // Check both English and Arabic patterns
            foreach (['en', 'ar'] as $language) {
                $languagePatterns = $patterns[$language] ?? [];

                foreach ($languagePatterns as $pattern) {
                    if ($this->matches($normalized, $pattern)) {
                        $matches[] = [
                            'category' => $categoryKey,
                            'pattern' => $pattern['text'],
                            'severity' => $severity,
                            'language' => $language,
                        ];
                    }
                }
            }
        }

        if (empty($matches)) {
            return CrisisInfo::none();
        }

        // Get the highest severity match
        $highest = $this->getHighestSeverityMatch($matches);

        return CrisisInfo::detected(
            severity: CrisisSeverity::from($highest['severity']),
            category: $this->mapCategory($highest['category']),
            keywords: array_unique(array_column($matches, 'pattern')),
        );
    }

    /**
     * Check if a specific category is detected.
     */
    public function detectsCategory(string $message, CrisisCategory $category): bool
    {
        $result = $this->check($message);
        return $result->detected && $result->category === $category;
    }

    /**
     * Get all matched keywords for debugging/logging.
     *
     * @return array<array{category: string, pattern: string, severity: string, language: string}>
     */
    public function getMatches(string $message): array
    {
        $normalized = $this->normalize($message);
        $matches = [];

        foreach ($this->lexicon as $categoryKey => $categoryData) {
            $patterns = $categoryData['patterns'] ?? [];
            $severity = $categoryData['severity'] ?? 'high';

            foreach (['en', 'ar'] as $language) {
                $languagePatterns = $patterns[$language] ?? [];

                foreach ($languagePatterns as $pattern) {
                    if ($this->matches($normalized, $pattern)) {
                        $matches[] = [
                            'category' => $categoryKey,
                            'pattern' => $pattern['text'],
                            'severity' => $severity,
                            'language' => $language,
                        ];
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Normalize text for comparison.
     */
    private function normalize(string $text): string
    {
        // Convert to lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        // Trim
        $text = trim($text);

        return $text;
    }

    /**
     * Check if text matches a pattern.
     *
     * @param array{text: string, type: string} $pattern
     */
    private function matches(string $text, array $pattern): bool
    {
        $patternText = mb_strtolower($pattern['text'], 'UTF-8');
        $type = $pattern['type'] ?? 'exact';

        if ($type === 'exact') {
            return str_contains($text, $patternText);
        }

        if ($type === 'regex') {
            return preg_match($patternText, $text) === 1;
        }

        return false;
    }

    /**
     * Get the highest severity match from a list of matches.
     *
     * @param array<array{category: string, pattern: string, severity: string, language: string}> $matches
     * @return array{category: string, pattern: string, severity: string, language: string}
     */
    private function getHighestSeverityMatch(array $matches): array
    {
        // Sort by severity (critical > high)
        usort($matches, function (array $a, array $b): int {
            $severityOrder = ['critical' => 0, 'high' => 1];
            return ($severityOrder[$a['severity']] ?? 2) <=> ($severityOrder[$b['severity']] ?? 2);
        });

        return $matches[0];
    }

    /**
     * Map category string to CrisisCategory enum.
     */
    private function mapCategory(string $category): CrisisCategory
    {
        return match ($category) {
            'suicide' => CrisisCategory::SUICIDE,
            'self_harm' => CrisisCategory::SELF_HARM,
            'harm_to_others' => CrisisCategory::HARM_TO_OTHERS,
            'abuse' => CrisisCategory::ABUSE,
            'medical_emergency' => CrisisCategory::MEDICAL_EMERGENCY,
            'psychosis' => CrisisCategory::PSYCHOSIS,
            default => CrisisCategory::SELF_HARM, // Default fallback
        };
    }

    /**
     * Load the default crisis lexicon from the package resources.
     *
     * @return array<string, array{severity: string, patterns: array<string, array<array{text: string, type: string}>>}>
     */
    private function loadDefaultLexicon(): array
    {
        $path = __DIR__ . '/../../resources/data/crisis_lexicon.json';

        if (!file_exists($path)) {
            throw new \RuntimeException('Crisis lexicon file not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Failed to read crisis lexicon file');
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid crisis lexicon JSON');
        }

        return $data['categories'] ?? [];
    }
}
