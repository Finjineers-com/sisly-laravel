<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Sisly\DTOs\GeoContext;

class GeoContextTest extends TestCase
{
    public function test_can_create_with_country_only(): void
    {
        $geo = new GeoContext(country: 'AE');

        $this->assertEquals('AE', $geo->country);
        $this->assertNull($geo->region);
        $this->assertNull($geo->city);
        $this->assertNull($geo->timezone);
    }

    public function test_can_create_with_all_fields(): void
    {
        $geo = new GeoContext(
            country: 'AE',
            region: 'Dubai',
            city: 'Dubai',
            timezone: 'Asia/Dubai',
        );

        $this->assertEquals('AE', $geo->country);
        $this->assertEquals('Dubai', $geo->region);
        $this->assertEquals('Dubai', $geo->city);
        $this->assertEquals('Asia/Dubai', $geo->timezone);
    }

    public function test_can_create_from_array(): void
    {
        $geo = GeoContext::fromArray([
            'country' => 'SA',
            'city' => 'Riyadh',
        ]);

        $this->assertEquals('SA', $geo->country);
        $this->assertEquals('Riyadh', $geo->city);
    }

    public function test_to_array_returns_correct_structure(): void
    {
        $geo = new GeoContext(country: 'KW', city: 'Kuwait City');
        $array = $geo->toArray();

        $this->assertEquals([
            'country' => 'KW',
            'region' => null,
            'city' => 'Kuwait City',
            'timezone' => null,
        ], $array);
    }

    /**
     * @dataProvider gccCountriesProvider
     */
    public function test_is_gcc_returns_true_for_gcc_countries(string $country): void
    {
        $geo = new GeoContext(country: $country);

        $this->assertTrue($geo->isGCC());
    }

    /**
     * @return array<array{string}>
     */
    public static function gccCountriesProvider(): array
    {
        return [
            ['AE'], // UAE
            ['SA'], // Saudi Arabia
            ['KW'], // Kuwait
            ['QA'], // Qatar
            ['BH'], // Bahrain
            ['OM'], // Oman
        ];
    }

    public function test_is_gcc_returns_false_for_non_gcc_countries(): void
    {
        $geo = new GeoContext(country: 'US');
        $this->assertFalse($geo->isGCC());

        $geo = new GeoContext(country: 'EG');
        $this->assertFalse($geo->isGCC());
    }
}
