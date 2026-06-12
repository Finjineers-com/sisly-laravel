<?php

declare(strict_types=1);

namespace Sisly\DTOs;

use Sisly\Enums\CrisisCategory;
use Sisly\Enums\CrisisSeverity;

/**
 * Crisis detection result.
 */
final class CrisisInfo
{
    /**
     * @param array<string> $keywordsMatched
     */
    public function __construct(
        public readonly bool $detected = false,
        public readonly ?CrisisSeverity $severity = null,
        public readonly ?CrisisCategory $category = null,
        public readonly bool $resourcesProvided = false,
        public readonly bool $interventionActive = false,
        public readonly array $keywordsMatched = [],
    ) {}

    /**
     * Create a "no crisis" instance.
     */
    public static function none(): self
    {
        return new self(detected: false);
    }

    /**
     * Create a crisis instance.
     *
     * @param array<string> $keywords
     */
    public static function detected(
        CrisisSeverity $severity,
        CrisisCategory $category,
        array $keywords = [],
    ): self {
        return new self(
            detected: true,
            severity: $severity,
            category: $category,
            keywordsMatched: $keywords,
        );
    }

    /**
     * Create instance with resources provided.
     */
    public function withResourcesProvided(): self
    {
        return new self(
            detected: $this->detected,
            severity: $this->severity,
            category: $this->category,
            resourcesProvided: true,
            interventionActive: true,
            keywordsMatched: $this->keywordsMatched,
        );
    }

    /**
     * Create instance from array.
     *
     * @param array{detected: bool, severity?: string|null, category?: string|null, resources_provided?: bool, intervention_active?: bool, keywords_matched?: array<string>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            detected: $data['detected'],
            severity: isset($data['severity']) ? CrisisSeverity::from($data['severity']) : null,
            category: isset($data['category']) ? CrisisCategory::from($data['category']) : null,
            resourcesProvided: $data['resources_provided'] ?? false,
            interventionActive: $data['intervention_active'] ?? false,
            keywordsMatched: $data['keywords_matched'] ?? [],
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array{detected: bool, severity: string|null, category: string|null, resources_provided: bool, intervention_active: bool, keywords_matched: array<string>}
     */
    public function toArray(): array
    {
        return [
            'detected' => $this->detected,
            'severity' => $this->severity?->value,
            'category' => $this->category?->value,
            'resources_provided' => $this->resourcesProvided,
            'intervention_active' => $this->interventionActive,
            'keywords_matched' => $this->keywordsMatched,
        ];
    }
}
