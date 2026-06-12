<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Arabic;

use PHPUnit\Framework\TestCase;
use Sisly\Arabic\LanguageDetector;

class LanguageDetectorTest extends TestCase
{
    private LanguageDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new LanguageDetector();
    }

    public function test_detects_english_text(): void
    {
        $this->assertEquals('en', $this->detector->detect('Hello, how are you?'));
        $this->assertEquals('en', $this->detector->detect('I feel anxious today'));
        $this->assertEquals('en', $this->detector->detect('The weather is nice'));
    }

    public function test_detects_arabic_text(): void
    {
        $this->assertEquals('ar', $this->detector->detect('مرحبا كيف حالك'));
        $this->assertEquals('ar', $this->detector->detect('أشعر بالقلق اليوم'));
        $this->assertEquals('ar', $this->detector->detect('الطقس جميل'));
    }

    public function test_detects_gulf_arabic_dialect(): void
    {
        // Gulf dialect phrases
        $this->assertEquals('ar', $this->detector->detect('شلونك؟'));
        $this->assertEquals('ar', $this->detector->detect('كيفك انت؟'));
        $this->assertEquals('ar', $this->detector->detect('وش الأخبار؟'));
    }

    public function test_handles_empty_string(): void
    {
        $this->assertEquals('en', $this->detector->detect(''));
        $this->assertEquals('en', $this->detector->detect('   '));
    }

    public function test_handles_mixed_language_primarily_english(): void
    {
        $text = 'Hello my name is Ahmed أحمد';
        $this->assertEquals('en', $this->detector->detect($text));
    }

    public function test_handles_mixed_language_primarily_arabic(): void
    {
        $text = 'مرحبا اسمي Ahmad أحمد وأنا سعيد';
        $this->assertEquals('ar', $this->detector->detect($text));
    }

    public function test_contains_arabic_returns_true(): void
    {
        $this->assertTrue($this->detector->containsArabic('Hello أحمد'));
        $this->assertTrue($this->detector->containsArabic('مرحبا'));
    }

    public function test_contains_arabic_returns_false(): void
    {
        $this->assertFalse($this->detector->containsArabic('Hello World'));
        $this->assertFalse($this->detector->containsArabic('12345'));
    }

    public function test_is_primarily_arabic(): void
    {
        $this->assertTrue($this->detector->isPrimarilyArabic('مرحبا كيف حالك'));
        $this->assertFalse($this->detector->isPrimarilyArabic('Hello World'));
    }

    public function test_is_primarily_english(): void
    {
        $this->assertTrue($this->detector->isPrimarilyEnglish('Hello World'));
        $this->assertFalse($this->detector->isPrimarilyEnglish('مرحبا كيف حالك'));
    }

    public function test_get_arabic_ratio(): void
    {
        // Pure Arabic
        $this->assertGreaterThan(0.9, $this->detector->getArabicRatio('مرحبا'));

        // Pure English
        $this->assertEquals(0.0, $this->detector->getArabicRatio('Hello'));

        // Mixed
        $ratio = $this->detector->getArabicRatio('Hello مرحبا');
        $this->assertGreaterThan(0.0, $ratio);
        $this->assertLessThan(1.0, $ratio);
    }

    public function test_count_arabic_characters(): void
    {
        $this->assertEquals(5, $this->detector->countArabicCharacters('مرحبا'));
        $this->assertEquals(0, $this->detector->countArabicCharacters('Hello'));
        $this->assertEquals(5, $this->detector->countArabicCharacters('Hello مرحبا'));
    }

    public function test_is_mixed_language(): void
    {
        // Pure languages are not mixed
        $this->assertFalse($this->detector->isMixedLanguage('Hello World'));
        $this->assertFalse($this->detector->isMixedLanguage('مرحبا كيف حالك'));

        // Mixed languages
        $this->assertTrue($this->detector->isMixedLanguage('Hello مرحبا World كيف'));
    }

    public function test_analyze_returns_complete_analysis(): void
    {
        $analysis = $this->detector->analyze('Hello مرحبا');

        $this->assertArrayHasKey('detected', $analysis);
        $this->assertArrayHasKey('arabic_ratio', $analysis);
        $this->assertArrayHasKey('arabic_count', $analysis);
        $this->assertArrayHasKey('is_mixed', $analysis);
        $this->assertArrayHasKey('contains_arabic', $analysis);

        $this->assertEquals(5, $analysis['arabic_count']);
        $this->assertTrue($analysis['contains_arabic']);
    }

    public function test_handles_numbers_and_punctuation(): void
    {
        // Numbers only
        $this->assertEquals('en', $this->detector->detect('12345'));

        // Punctuation only
        $this->assertEquals('en', $this->detector->detect('!!!???'));

        // Arabic with numbers
        $this->assertEquals('ar', $this->detector->detect('مرحبا 123'));
    }

    public function test_custom_threshold(): void
    {
        // With higher threshold, mixed text might be detected as English
        $detector = new LanguageDetector(0.6);

        // This text has ~50% Arabic, so with 0.6 threshold it should be English
        $text = 'Hello World مرحبا';
        $this->assertEquals('en', $detector->detect($text));

        // With lower threshold, same text might be Arabic
        $detectorLow = new LanguageDetector(0.2);
        $this->assertEquals('ar', $detectorLow->detect($text));
    }

    /**
     * @dataProvider arabicCharacterRangesProvider
     */
    public function test_detects_various_arabic_character_ranges(string $text, bool $expectedArabic): void
    {
        $this->assertEquals($expectedArabic, $this->detector->containsArabic($text));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function arabicCharacterRangesProvider(): array
    {
        return [
            'basic arabic' => ['العربية', true],
            'arabic with diacritics' => ['مَرْحَبًا', true],
            'arabic numerals' => ['٠١٢٣٤٥', true], // Eastern Arabic numerals
            'persian/farsi' => ['سلام', true], // Uses Arabic script
            'english only' => ['Hello', false],
            'latin extended' => ['Héllo', false],
        ];
    }

    public function test_handles_unicode_edge_cases(): void
    {
        // Zero-width characters
        $this->assertEquals('ar', $this->detector->detect("مرحبا\u{200B}"));

        // Right-to-left mark
        $this->assertEquals('ar', $this->detector->detect("مرحبا\u{200F}"));
    }
}
