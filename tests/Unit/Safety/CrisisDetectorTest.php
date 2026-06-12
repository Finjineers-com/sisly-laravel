<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Safety;

use PHPUnit\Framework\TestCase;
use Sisly\Enums\CrisisCategory;
use Sisly\Enums\CrisisSeverity;
use Sisly\Safety\CrisisDetector;

class CrisisDetectorTest extends TestCase
{
    private CrisisDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new CrisisDetector();
    }

    // ==================== ENGLISH SUICIDE DETECTION ====================

    /**
     * @dataProvider englishSuicideKeywordsProvider
     */
    public function test_detects_english_suicide_keywords(string $message): void
    {
        $result = $this->detector->check($message);

        $this->assertTrue($result->detected, "Failed to detect suicide keyword in: {$message}");
        $this->assertEquals(CrisisSeverity::CRITICAL, $result->severity);
        $this->assertEquals(CrisisCategory::SUICIDE, $result->category);
    }

    /**
     * @return array<array{string}>
     */
    public static function englishSuicideKeywordsProvider(): array
    {
        return [
            ['I want to kill myself'],
            ['i want to die'],
            ['I want to end my life'],
            ['thinking about suicide'],
            ["I'm suicidal"],
            ["I don't want to live anymore"],
            ['wish i was dead'],
            ["I'd be better off dead"],
            ['no reason to live'],
            ['going to end it all'],
            ["I can't go on anymore"],
            ['I want to disappear forever'],
            ["I'm planning to end everything"],
            ["I'm going to kill myself tonight"],
        ];
    }

    // ==================== ARABIC SUICIDE DETECTION ====================

    /**
     * @dataProvider arabicSuicideKeywordsProvider
     */
    public function test_detects_arabic_suicide_keywords(string $message): void
    {
        $result = $this->detector->check($message);

        $this->assertTrue($result->detected, "Failed to detect Arabic suicide keyword in: {$message}");
        $this->assertEquals(CrisisSeverity::CRITICAL, $result->severity);
        $this->assertEquals(CrisisCategory::SUICIDE, $result->category);
    }

    /**
     * @return array<array{string}>
     */
    public static function arabicSuicideKeywordsProvider(): array
    {
        return [
            ['أريد أن أموت'],
            ['اريد ان اموت'],
            ['أريد أقتل نفسي'],
            ['اقتل نفسي'],
            ['أفكر في الانتحار'],
            ['سأنتحر'],
            ['أريد أنهي حياتي'],
            ['لا أريد أن أعيش'],
            ['لا اريد ان اعيش'],
            ['الموت أفضل من هذه الحياة'],
            ['تعبت من الحياة'],
        ];
    }

    // ==================== SELF-HARM DETECTION ====================

    /**
     * @dataProvider selfHarmKeywordsProvider
     */
    public function test_detects_self_harm_keywords(string $message): void
    {
        $result = $this->detector->check($message);

        $this->assertTrue($result->detected, "Failed to detect self-harm keyword in: {$message}");
        $this->assertEquals(CrisisSeverity::HIGH, $result->severity);
        $this->assertEquals(CrisisCategory::SELF_HARM, $result->category);
    }

    /**
     * @return array<array{string}>
     */
    public static function selfHarmKeywordsProvider(): array
    {
        return [
            ['I want to hurt myself'],
            ['I keep cutting myself'],
            ["I've been self harming"],
            ['self-harm helps me cope'],
            ['burning myself'],
            ['I hit myself when angry'],
            ['أريد أؤذي نفسي'],
            ['أجرح نفسي'],
            ['إيذاء النفس'],
        ];
    }

    // ==================== ABUSE DETECTION ====================

    /**
     * @dataProvider abuseKeywordsProvider
     */
    public function test_detects_abuse_keywords(string $message): void
    {
        $result = $this->detector->check($message);

        $this->assertTrue($result->detected, "Failed to detect abuse keyword in: {$message}");
        $this->assertEquals(CrisisSeverity::CRITICAL, $result->severity);
        $this->assertEquals(CrisisCategory::ABUSE, $result->category);
    }

    /**
     * @return array<array{string}>
     */
    public static function abuseKeywordsProvider(): array
    {
        return [
            ["I'm being abused at home"],
            ['my partner is abusing me'],
            ['he hits me every day'],
            ['she beats me'],
            ["I've been sexually abused"],
            ['I was molested'],
            ['someone raped me'],
            ['domestic violence at home'],
            ['زوجي يضربني'],
            ['يؤذيني كل يوم'],
            ['تعرضت للاغتصاب'],
        ];
    }

    // ==================== HARM TO OTHERS DETECTION ====================

    /**
     * @dataProvider harmToOthersKeywordsProvider
     */
    public function test_detects_harm_to_others_keywords(string $message): void
    {
        $result = $this->detector->check($message);

        $this->assertTrue($result->detected, "Failed to detect harm-to-others keyword in: {$message}");
        $this->assertEquals(CrisisSeverity::CRITICAL, $result->severity);
        $this->assertEquals(CrisisCategory::HARM_TO_OTHERS, $result->category);
    }

    /**
     * @return array<array{string}>
     */
    public static function harmToOthersKeywordsProvider(): array
    {
        return [
            ['I want to kill someone'],
            ['going to hurt them'],
            ["I'll make them pay"],
            ['planning to attack my coworker'],
            ['أريد أن أقتله'],
            ['سأقتله'],
        ];
    }

    // ==================== MEDICAL EMERGENCY DETECTION ====================

    /**
     * @dataProvider medicalEmergencyKeywordsProvider
     */
    public function test_detects_medical_emergency_keywords(string $message): void
    {
        $result = $this->detector->check($message);

        $this->assertTrue($result->detected, "Failed to detect medical emergency in: {$message}");
        $this->assertEquals(CrisisSeverity::CRITICAL, $result->severity);
        $this->assertEquals(CrisisCategory::MEDICAL_EMERGENCY, $result->category);
    }

    /**
     * @return array<array{string}>
     */
    public static function medicalEmergencyKeywordsProvider(): array
    {
        return [
            ['I took an overdose'],
            ['took too many pills'],
            ["I can't breathe"],
            ["I'm bleeding heavily"],
            ['I drank bleach'],
            ['جرعة زائدة من الدواء'],
            ['لا أستطيع التنفس'],
        ];
    }

    // ==================== PSYCHOSIS DETECTION ====================

    /**
     * @dataProvider psychosisKeywordsProvider
     */
    public function test_detects_psychosis_keywords(string $message): void
    {
        $result = $this->detector->check($message);

        $this->assertTrue($result->detected, "Failed to detect psychosis keyword in: {$message}");
        $this->assertEquals(CrisisSeverity::HIGH, $result->severity);
        $this->assertEquals(CrisisCategory::PSYCHOSIS, $result->category);
    }

    /**
     * @return array<array{string}>
     */
    public static function psychosisKeywordsProvider(): array
    {
        return [
            ["I'm hearing voices that tell me things"],
            ['the voices telling me to do things'],
            ['people are watching me all the time'],
            ["I'm being followed everywhere"],
            ["they're after me"],
            ['the government is tracking my thoughts'],
            ['أسمع أصوات غريبة'],
            ['الأصوات تخبرني'],
            ['يراقبونني في كل مكان'],
        ];
    }

    // ==================== NON-CRISIS MESSAGES ====================

    /**
     * @dataProvider nonCrisisMessagesProvider
     */
    public function test_does_not_flag_non_crisis_messages(string $message): void
    {
        $result = $this->detector->check($message);

        $this->assertFalse($result->detected, "Incorrectly flagged non-crisis message: {$message}");
    }

    /**
     * @return array<array{string}>
     */
    public static function nonCrisisMessagesProvider(): array
    {
        return [
            ["I'm feeling anxious about my presentation"],
            ['I had a bad day at work'],
            ["I'm stressed about deadlines"],
            ['My boss is frustrating me'],
            ["I can't sleep well lately"],
            ['I feel overwhelmed with tasks'],
            ['عندي اجتماع مهم بكرا وأنا قلقان'],
            ['الشغل ضاغط علي'],
            ['I feel sad sometimes'],
            ['I worry a lot'],
            ['اشعر بالضيق'],
        ];
    }

    // ==================== EDGE CASES ====================

    public function test_handles_empty_message(): void
    {
        $result = $this->detector->check('');

        $this->assertFalse($result->detected);
    }

    public function test_handles_whitespace_only_message(): void
    {
        $result = $this->detector->check('   \n\t   ');

        $this->assertFalse($result->detected);
    }

    public function test_detection_is_case_insensitive(): void
    {
        $result1 = $this->detector->check('I WANT TO KILL MYSELF');
        $result2 = $this->detector->check('i want to kill myself');
        $result3 = $this->detector->check('I Want To Kill Myself');

        $this->assertTrue($result1->detected);
        $this->assertTrue($result2->detected);
        $this->assertTrue($result3->detected);
    }

    public function test_detection_works_with_surrounding_text(): void
    {
        $result = $this->detector->check('Yesterday I was thinking that I want to kill myself because of work stress');

        $this->assertTrue($result->detected);
        $this->assertEquals(CrisisCategory::SUICIDE, $result->category);
    }

    public function test_returns_matched_keywords(): void
    {
        $result = $this->detector->check('I want to die and kill myself');

        $this->assertTrue($result->detected);
        $this->assertNotEmpty($result->keywordsMatched);
        $this->assertContains('want to die', $result->keywordsMatched);
    }

    public function test_get_matches_returns_detailed_info(): void
    {
        $matches = $this->detector->getMatches('I want to kill myself');

        $this->assertNotEmpty($matches);
        $this->assertArrayHasKey('category', $matches[0]);
        $this->assertArrayHasKey('pattern', $matches[0]);
        $this->assertArrayHasKey('severity', $matches[0]);
        $this->assertArrayHasKey('language', $matches[0]);
    }

    public function test_detects_category_method(): void
    {
        $this->assertTrue($this->detector->detectsCategory('I want to kill myself', CrisisCategory::SUICIDE));
        $this->assertFalse($this->detector->detectsCategory('I want to kill myself', CrisisCategory::ABUSE));
    }

    public function test_critical_severity_takes_priority_over_high(): void
    {
        // Message with both suicide (critical) and self-harm (high)
        $result = $this->detector->check('I want to kill myself and I cut myself');

        $this->assertEquals(CrisisSeverity::CRITICAL, $result->severity);
    }

    public function test_mixed_language_detection(): void
    {
        $result = $this->detector->check('I feel bad أريد أن أموت');

        $this->assertTrue($result->detected);
        $this->assertEquals(CrisisCategory::SUICIDE, $result->category);
    }
}
