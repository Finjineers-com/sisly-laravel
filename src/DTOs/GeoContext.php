<?php

declare(strict_types=1);

namespace Sisly\DTOs;

/**
 * Location context for crisis resource routing.
 */
final class GeoContext
{
    public function __construct(
        public readonly string $country,      // ISO 3166-1 alpha-2 (e.g., "AE", "SA")
        public readonly ?string $region = null,
        public readonly ?string $city = null,
        public readonly ?string $timezone = null,
    ) {}

    /**
     * Create instance from array.
     *
     * @param array{country: string, region?: string|null, city?: string|null, timezone?: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            country: $data['country'],
            region: $data['region'] ?? null,
            city: $data['city'] ?? null,
            timezone: $data['timezone'] ?? null,
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array{country: string, region: string|null, city: string|null, timezone: string|null}
     */
    public function toArray(): array
    {
        return [
            'country' => $this->country,
            'region' => $this->region,
            'city' => $this->city,
            'timezone' => $this->timezone,
        ];
    }

    /**
     * Check if this is a GCC country.
     */
    public function isGCC(): bool
    {
        return in_array($this->country, ['AE', 'SA', 'KW', 'QA', 'BH', 'OM'], true);
    }
}
