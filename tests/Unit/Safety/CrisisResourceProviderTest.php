<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Safety;

use PHPUnit\Framework\TestCase;
use Sisly\DTOs\GeoContext;
use Sisly\Safety\CrisisResourceProvider;

class CrisisResourceProviderTest extends TestCase
{
    private CrisisResourceProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new CrisisResourceProvider();
    }

    // ==================== COUNTRY RESOURCE LOOKUP ====================

    /**
     * @dataProvider gccCountriesProvider
     */
    public function test_returns_resources_for_gcc_countries(string $countryCode): void
    {
        $resources = $this->provider->getForCountry($countryCode);

        $this->assertNotEmpty($resources['country']);
        $this->assertNotEmpty($resources['emergency_number']);
        $this->assertIsArray($resources['resources']);
        $this->assertNotEmpty($resources['resources']);
    }

    /**
     * @return array<array{string}>
     */
    public static function gccCountriesProvider(): array
    {
        return [
            ['AE'],
            ['SA'],
            ['KW'],
            ['QA'],
            ['BH'],
            ['OM'],
        ];
    }

    public function test_uae_has_correct_emergency_number(): void
    {
        $resources = $this->provider->getForCountry('AE');

        $this->assertEquals('999', $resources['emergency_number']);
    }

    public function test_saudi_has_correct_emergency_number(): void
    {
        $resources = $this->provider->getForCountry('SA');

        $this->assertEquals('911', $resources['emergency_number']);
    }

    public function test_kuwait_has_correct_emergency_number(): void
    {
        $resources = $this->provider->getForCountry('KW');

        $this->assertEquals('112', $resources['emergency_number']);
    }

    public function test_returns_hotline_for_uae(): void
    {
        $resources = $this->provider->getForCountry('AE');

        $this->assertNotNull($resources['hotline']);
        $this->assertArrayHasKey('name', $resources['hotline']);
        $this->assertArrayHasKey('phone', $resources['hotline']);
    }

    public function test_returns_hotline_for_saudi(): void
    {
        $hotline = $this->provider->getHotline('SA');

        $this->assertNotNull($hotline);
        $this->assertEquals('920033360', $hotline['phone']);
    }

    public function test_country_lookup_is_case_insensitive(): void
    {
        $upper = $this->provider->getForCountry('AE');
        $lower = $this->provider->getForCountry('ae');
        $mixed = $this->provider->getForCountry('Ae');

        $this->assertEquals($upper['country'], $lower['country']);
        $this->assertEquals($upper['country'], $mixed['country']);
    }

    // ==================== UNKNOWN COUNTRY HANDLING ====================

    public function test_returns_default_for_unknown_country(): void
    {
        $resources = $this->provider->getForCountry('XX');

        $this->assertEquals('Unknown', $resources['country']);
        $this->assertEquals('911', $resources['emergency_number']);
        $this->assertNull($resources['hotline']);
    }

    public function test_has_resources_for_returns_false_for_unknown(): void
    {
        $this->assertFalse($this->provider->hasResourcesFor('XX'));
        $this->assertFalse($this->provider->hasResourcesFor('ZZ'));
    }

    public function test_has_resources_for_returns_true_for_gcc(): void
    {
        $this->assertTrue($this->provider->hasResourcesFor('AE'));
        $this->assertTrue($this->provider->hasResourcesFor('SA'));
    }

    // ==================== GEO CONTEXT LOOKUP ====================

    public function test_get_for_geo_context_returns_country_resources(): void
    {
        $geo = new GeoContext(country: 'AE');
        $resources = $this->provider->getForGeoContext($geo);

        $this->assertEquals('United Arab Emirates', $resources['country']);
        $this->assertNotEmpty($resources['resources']);
    }

    public function test_get_for_geo_context_includes_regional_resources(): void
    {
        $geo = new GeoContext(country: 'AE', region: 'Dubai');
        $resources = $this->provider->getForGeoContext($geo);

        // Dubai should have additional resources
        $hasRegionalResource = false;
        foreach ($resources['resources'] as $resource) {
            if (str_contains($resource['id'] ?? '', 'dubai')) {
                $hasRegionalResource = true;
                break;
            }
        }

        $this->assertTrue($hasRegionalResource, 'Dubai regional resources not found');
    }

    // ==================== RESOURCE STRUCTURE ====================

    public function test_resources_have_required_fields(): void
    {
        $resources = $this->provider->getForCountry('AE');

        foreach ($resources['resources'] as $resource) {
            $this->assertArrayHasKey('id', $resource);
            $this->assertArrayHasKey('name', $resource);
            $this->assertArrayHasKey('phone', $resource);
            $this->assertArrayHasKey('type', $resource);
        }
    }

    public function test_resources_include_arabic_names(): void
    {
        $resources = $this->provider->getForCountry('AE');

        $hasArabicName = false;
        foreach ($resources['resources'] as $resource) {
            if (!empty($resource['name_ar'])) {
                $hasArabicName = true;
                break;
            }
        }

        $this->assertTrue($hasArabicName);
    }

    public function test_resources_are_sorted_by_priority(): void
    {
        $resources = $this->provider->getForCountry('AE');

        // Emergency should come before hotline
        $emergencyIndex = null;
        $hotlineIndex = null;

        foreach ($resources['resources'] as $index => $resource) {
            if ($resource['type'] === 'emergency' && $emergencyIndex === null) {
                $emergencyIndex = $index;
            }
            if ($resource['type'] === 'hotline' && $hotlineIndex === null) {
                $hotlineIndex = $index;
            }
        }

        if ($emergencyIndex !== null && $hotlineIndex !== null) {
            $this->assertLessThan($hotlineIndex, $emergencyIndex);
        }
    }

    // ==================== HELPER METHODS ====================

    public function test_get_emergency_number_returns_correct_number(): void
    {
        $this->assertEquals('999', $this->provider->getEmergencyNumber('AE'));
        $this->assertEquals('911', $this->provider->getEmergencyNumber('SA'));
        $this->assertEquals('112', $this->provider->getEmergencyNumber('KW'));
        $this->assertEquals('999', $this->provider->getEmergencyNumber('QA'));
        $this->assertEquals('999', $this->provider->getEmergencyNumber('BH'));
        $this->assertEquals('9999', $this->provider->getEmergencyNumber('OM'));
    }

    public function test_get_supported_countries_returns_gcc(): void
    {
        $countries = $this->provider->getSupportedCountries();

        $this->assertContains('AE', $countries);
        $this->assertContains('SA', $countries);
        $this->assertContains('KW', $countries);
        $this->assertContains('QA', $countries);
        $this->assertContains('BH', $countries);
        $this->assertContains('OM', $countries);
    }
}
