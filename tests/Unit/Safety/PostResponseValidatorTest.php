<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Safety;

use PHPUnit\Framework\TestCase;
use Sisly\Safety\PostResponseValidator;

class PostResponseValidatorTest extends TestCase
{
    private PostResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PostResponseValidator();
    }

    // ==================== SAFE RESPONSES ====================

    /**
     * @dataProvider safeResponsesProvider
     */
    public function test_allows_safe_responses(string $response): void
    {
        $result = $this->validator->validate($response);

        $this->assertTrue($result->valid, "Safe response was incorrectly blocked: {$response}");
        $this->assertFalse($result->blocked);
    }

    /**
     * @return array<array{string}>
     */
    public static function safeResponsesProvider(): array
    {
        return [
            ["I hear you. That sounds really challenging."],
            ["Can you tell me more about what you're experiencing?"],
            ["It's understandable to feel that way."],
            ["Many people feel this way when facing similar situations."],
            ["Let's try a breathing exercise together."],
            ["What matters most to you right now?"],
            ["You've shown a lot of strength in sharing this."],
            ["How has this been affecting you?"],
            ["أسمعك. هذا يبدو صعبًا."],
            ["أخبرني المزيد عما تشعر به."],
        ];
    }

    // ==================== BLOCKED RESPONSES ====================

    /**
     * @dataProvider harmfulResponsesProvider
     */
    public function test_blocks_harmful_responses(string $response): void
    {
        $result = $this->validator->validate($response);

        $this->assertFalse($result->valid, "Harmful response was not blocked: {$response}");
        $this->assertTrue($result->blocked);
        $this->assertNotNull($result->matchedPattern);
    }

    /**
     * @return array<array{string}>
     */
    public static function harmfulResponsesProvider(): array
    {
        return [
            // Harmful suggestions
            ['You should kill yourself if you feel that bad'],
            ['Maybe you should hurt yourself to feel better'],
            ["Here's how to end your suffering permanently"],

            // Medical diagnosis
            ['You have depression based on what you told me'],
            ['You are clearly bipolar'],
            ['You seem to have anxiety disorder'],
            ['I diagnose you with PTSD'],

            // Medication advice
            ['You should take antidepressants'],
            ['Try using Xanax for your anxiety'],
            ['You should get some Prozac'],
            ['Increase your dose of medication'],
            ['Stop taking your medication immediately'],

            // Dangerous minimization
            ["It's not that bad, just get over it"],
            ['Just snap out of it and be happy'],
            ['Think positive and it will all be fine'],
        ];
    }

    // ==================== WARNING RESPONSES ====================

    public function test_flags_clinical_terminology(): void
    {
        $response = 'You might want to consider seeing a therapist about this.';
        $result = $this->validator->validate($response);

        $this->assertTrue($result->valid); // Not blocked, just warned
        $this->assertTrue($result->hasWarnings());
    }

    public function test_flags_directive_language(): void
    {
        $response = 'You must do this exercise every day.';
        $result = $this->validator->validate($response);

        $this->assertTrue($result->valid); // Not blocked, just warned
        $this->assertTrue($result->hasWarnings());
    }

    // ==================== IS_SAFE METHOD ====================

    public function test_is_safe_returns_true_for_safe_response(): void
    {
        $this->assertTrue($this->validator->isSafe("I'm here to listen."));
    }

    public function test_is_safe_returns_false_for_harmful_response(): void
    {
        $this->assertFalse($this->validator->isSafe('You should kill yourself'));
    }

    // ==================== FALLBACK RESPONSE ====================

    public function test_get_fallback_response_returns_english_by_default(): void
    {
        $fallback = $this->validator->getFallbackResponse();

        $this->assertStringContainsString("I'm here to listen", $fallback);
    }

    public function test_get_fallback_response_returns_arabic_when_requested(): void
    {
        $fallback = $this->validator->getFallbackResponse('ar');

        $this->assertStringContainsString('أنا هنا', $fallback);
    }

    // ==================== CUSTOM PATTERNS ====================

    public function test_add_custom_prohibited_pattern(): void
    {
        $this->validator->addProhibitedPattern('/forbidden phrase/i');

        $result = $this->validator->validate('This contains a forbidden phrase here');

        $this->assertFalse($result->valid);
        $this->assertTrue($result->blocked);
    }

    public function test_add_custom_warning_pattern(): void
    {
        $this->validator->addWarningPattern('/custom warning/i');

        $result = $this->validator->validate('This has a custom warning phrase');

        $this->assertTrue($result->valid);
        $this->assertTrue($result->hasWarnings());
    }

    // ==================== EDGE CASES ====================

    public function test_handles_empty_response(): void
    {
        $result = $this->validator->validate('');

        $this->assertTrue($result->valid);
    }

    public function test_handles_very_long_response(): void
    {
        $longResponse = str_repeat("I hear you. That sounds challenging. ", 100);
        $result = $this->validator->validate($longResponse);

        $this->assertTrue($result->valid);
    }

    public function test_detection_is_case_insensitive(): void
    {
        $result1 = $this->validator->validate('YOU SHOULD KILL YOURSELF');
        $result2 = $this->validator->validate('you should kill yourself');

        $this->assertFalse($result1->valid);
        $this->assertFalse($result2->valid);
    }

    public function test_partial_matches_are_detected(): void
    {
        // "you should hurt yourself" embedded in longer text
        $response = "Some people think you should hurt yourself but that's not true";
        $result = $this->validator->validate($response);

        $this->assertFalse($result->valid);
    }

    // ==================== VALIDATION RESULT OBJECT ====================

    public function test_validation_result_has_correct_structure(): void
    {
        $result = $this->validator->validate('You have depression');

        $this->assertIsBool($result->valid);
        $this->assertIsBool($result->blocked);
        $this->assertIsArray($result->warnings);
    }

    public function test_blocked_result_includes_matched_pattern(): void
    {
        $result = $this->validator->validate('You should kill yourself');

        $this->assertNotNull($result->matchedPattern);
        $this->assertNotNull($result->reason);
    }
}
