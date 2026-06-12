<?php

declare(strict_types=1);

namespace Sisly\Safety;

use Sisly\DTOs\GeoContext;

/**
 * Provides crisis resources (hotlines, emergency contacts) by country.
 */
class CrisisResourceProvider
{
    /**
     * @var array<string, mixed>
     */
    private array $resources;

    /**
     * @var array<string, array{label: string, label_ar: string, priority: int}>
     */
    private array $resourceTypes;

    /**
     * @param array<string, mixed>|null $resources Loaded resources data, or null to load from default
     */
    public function __construct(?array $resources = null)
    {
        if ($resources === null) {
            $data = $this->loadDefaultResources();
            $this->resources = $data['countries'] ?? [];
            $this->resourceTypes = $data['resource_types'] ?? [];
        } else {
            $this->resources = $resources['countries'] ?? $resources;
            $this->resourceTypes = $resources['resource_types'] ?? [];
        }
    }

    /**
     * Get crisis resources for a country.
     *
     * @return array{
     *   country: string,
     *   country_ar: string,
     *   emergency_number: string,
     *   hotline: array{name: string, name_ar: string, phone: string}|null,
     *   resources: array<array{id: string, name: string, name_ar: string, phone: string, type: string, available_24_7: bool}>
     * }
     */
    public function getForCountry(string $countryCode): array
    {
        $countryCode = strtoupper($countryCode);
        $countryData = $this->resources[$countryCode] ?? null;

        if ($countryData === null) {
            return $this->getDefaultResources();
        }

        $resources = $countryData['resources'] ?? [];
        $hotline = $this->findPrimaryHotline($resources);

        return [
            'country' => $countryData['name'] ?? $countryCode,
            'country_ar' => $countryData['name_ar'] ?? '',
            'emergency_number' => $countryData['emergency_number'] ?? '911',
            'hotline' => $hotline,
            'resources' => $this->sortResourcesByPriority($resources),
        ];
    }

    /**
     * Get crisis resources with regional specifics.
     *
     * @return array{
     *   country: string,
     *   country_ar: string,
     *   emergency_number: string,
     *   hotline: array{name: string, name_ar: string, phone: string}|null,
     *   resources: array<array{id: string, name: string, name_ar: string, phone: string, type: string, available_24_7: bool}>
     * }
     */
    public function getForGeoContext(GeoContext $geo): array
    {
        $base = $this->getForCountry($geo->country);

        // Add regional resources if available
        if ($geo->region !== null) {
            $countryData = $this->resources[strtoupper($geo->country)] ?? [];
            $regions = $countryData['regions'] ?? [];
            $regionData = $regions[$geo->region] ?? null;

            if ($regionData !== null && isset($regionData['additional_resources'])) {
                $base['resources'] = array_merge(
                    $regionData['additional_resources'],
                    $base['resources']
                );
                $base['resources'] = $this->sortResourcesByPriority($base['resources']);
            }
        }

        return $base;
    }

    /**
     * Get the emergency number for a country.
     */
    public function getEmergencyNumber(string $countryCode): string
    {
        $countryCode = strtoupper($countryCode);
        $countryData = $this->resources[$countryCode] ?? null;

        return $countryData['emergency_number'] ?? '911';
    }

    /**
     * Get the primary mental health hotline for a country.
     *
     * @return array{name: string, name_ar: string, phone: string}|null
     */
    public function getHotline(string $countryCode): ?array
    {
        $countryCode = strtoupper($countryCode);
        $countryData = $this->resources[$countryCode] ?? null;

        if ($countryData === null) {
            return null;
        }

        return $this->findPrimaryHotline($countryData['resources'] ?? []);
    }

    /**
     * Check if resources are available for a country.
     */
    public function hasResourcesFor(string $countryCode): bool
    {
        return isset($this->resources[strtoupper($countryCode)]);
    }

    /**
     * Get all supported country codes.
     *
     * @return array<string>
     */
    public function getSupportedCountries(): array
    {
        return array_keys($this->resources);
    }

    /**
     * Find the primary mental health hotline from resources.
     *
     * @param array<array<string, mixed>> $resources
     * @return array{name: string, name_ar: string, phone: string}|null
     */
    private function findPrimaryHotline(array $resources): ?array
    {
        foreach ($resources as $resource) {
            if (($resource['type'] ?? '') === 'hotline' && ($resource['available_24_7'] ?? false)) {
                return [
                    'name' => $resource['name'] ?? '',
                    'name_ar' => $resource['name_ar'] ?? '',
                    'phone' => $resource['phone'] ?? '',
                ];
            }
        }

        // Fallback to any hotline
        foreach ($resources as $resource) {
            if (($resource['type'] ?? '') === 'hotline') {
                return [
                    'name' => $resource['name'] ?? '',
                    'name_ar' => $resource['name_ar'] ?? '',
                    'phone' => $resource['phone'] ?? '',
                ];
            }
        }

        return null;
    }

    /**
     * Sort resources by type priority.
     *
     * @param array<array<string, mixed>> $resources
     * @return array<array<string, mixed>>
     */
    private function sortResourcesByPriority(array $resources): array
    {
        usort($resources, function (array $a, array $b): int {
            $typeA = $a['type'] ?? 'other';
            $typeB = $b['type'] ?? 'other';

            $priorityA = $this->resourceTypes[$typeA]['priority'] ?? 99;
            $priorityB = $this->resourceTypes[$typeB]['priority'] ?? 99;

            return $priorityA <=> $priorityB;
        });

        return $resources;
    }

    /**
     * Get default resources when country is not in database.
     *
     * @return array{
     *   country: string,
     *   country_ar: string,
     *   emergency_number: string,
     *   hotline: null,
     *   resources: array<mixed>
     * }
     */
    private function getDefaultResources(): array
    {
        return [
            'country' => 'Unknown',
            'country_ar' => '',
            'emergency_number' => '911',
            'hotline' => null,
            'resources' => [],
        ];
    }

    /**
     * Load the default crisis resources from the package.
     *
     * @return array<string, mixed>
     */
    private function loadDefaultResources(): array
    {
        $path = __DIR__ . '/../../resources/data/crisis_resources.json';

        if (!file_exists($path)) {
            throw new \RuntimeException('Crisis resources file not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Failed to read crisis resources file');
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid crisis resources JSON');
        }

        return $data;
    }
}
