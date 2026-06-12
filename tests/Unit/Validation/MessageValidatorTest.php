<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Sisly\Exceptions\InvalidMessageException;
use Sisly\Validation\MessageValidator;

class MessageValidatorTest extends TestCase
{
    private MessageValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new MessageValidator();
    }

    public function test_validate_returns_normalized_message(): void
    {
        $result = $this->validator->validate('  Hello World  ');
        $this->assertEquals('Hello World', $result);
    }

    public function test_validate_throws_for_empty_message(): void
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('empty');

        $this->validator->validate('');
    }

    public function test_validate_throws_for_whitespace_only(): void
    {
        $this->expectException(InvalidMessageException::class);

        $this->validator->validate('   ');
    }

    public function test_validate_throws_for_too_long_message(): void
    {
        $validator = new MessageValidator(100);
        $longMessage = str_repeat('a', 150);

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('exceeds');

        $validator->validate($longMessage);
    }

    public function test_validate_safe_returns_valid_result(): void
    {
        $result = $this->validator->validateSafe('Hello World');

        $this->assertTrue($result['valid']);
        $this->assertEquals('Hello World', $result['message']);
        $this->assertNull($result['error']);
    }

    public function test_validate_safe_returns_invalid_for_empty(): void
    {
        $result = $this->validator->validateSafe('');

        $this->assertFalse($result['valid']);
        $this->assertNotNull($result['error']);
    }

    public function test_validate_safe_returns_invalid_for_too_long(): void
    {
        $validator = new MessageValidator(50);
        $result = $validator->validateSafe(str_repeat('a', 100));

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('exceeds', $result['error']);
    }

    public function test_normalize_collapses_whitespace(): void
    {
        $result = $this->validator->normalize('Hello    World');
        $this->assertEquals('Hello World', $result);
    }

    public function test_normalize_preserves_paragraph_breaks(): void
    {
        $result = $this->validator->normalize("Hello\n\nWorld");
        $this->assertEquals("Hello\n\nWorld", $result);
    }

    public function test_normalize_collapses_excessive_newlines(): void
    {
        $result = $this->validator->normalize("Hello\n\n\n\n\nWorld");
        $this->assertEquals("Hello\n\nWorld", $result);
    }

    public function test_truncate_returns_original_if_under_limit(): void
    {
        $result = $this->validator->truncate('Hello World', 100);
        $this->assertEquals('Hello World', $result);
    }

    public function test_truncate_adds_ellipsis(): void
    {
        $result = $this->validator->truncate('Hello World Test', 10);
        $this->assertStringEndsWith('...', $result);
        $this->assertLessThanOrEqual(13, strlen($result)); // 10 + 3 for "..."
    }

    public function test_truncate_at_word_boundary(): void
    {
        $result = $this->validator->truncate('Hello World Test Message Here', 20);
        // Should break at word boundary
        $this->assertStringNotContainsString('Mes', $result);
    }

    public function test_is_empty(): void
    {
        $this->assertTrue($this->validator->isEmpty(''));
        $this->assertTrue($this->validator->isEmpty('   '));
        $this->assertFalse($this->validator->isEmpty('Hello'));
    }

    public function test_is_too_long(): void
    {
        $validator = new MessageValidator(10);

        $this->assertFalse($validator->isTooLong('Hello'));
        $this->assertTrue($validator->isTooLong('Hello World!'));
    }

    public function test_get_and_set_max_length(): void
    {
        $this->assertEquals(MessageValidator::DEFAULT_MAX_LENGTH, $this->validator->getMaxLength());

        $this->validator->setMaxLength(100);
        $this->assertEquals(100, $this->validator->getMaxLength());
    }

    public function test_sanitize_removes_null_bytes(): void
    {
        $result = $this->validator->sanitize("Hello\0World");
        $this->assertEquals('HelloWorld', $result);
    }

    public function test_sanitize_removes_control_characters(): void
    {
        $result = $this->validator->sanitize("Hello\x00\x08World");
        $this->assertEquals('HelloWorld', $result);
    }

    public function test_sanitize_preserves_newlines(): void
    {
        $result = $this->validator->sanitize("Hello\nWorld");
        $this->assertStringContainsString("\n", $result);
    }

    public function test_get_stats_returns_correct_values(): void
    {
        $stats = $this->validator->getStats('Hello World');

        $this->assertEquals(11, $stats['length']);
        $this->assertEquals(2, $stats['word_count']);
        $this->assertEquals(1, $stats['line_count']);
        $this->assertTrue($stats['is_valid']);
    }

    public function test_get_stats_counts_lines(): void
    {
        $stats = $this->validator->getStats("Hello\nWorld\nTest");

        $this->assertEquals(3, $stats['line_count']);
    }

    public function test_get_stats_is_valid_false_for_empty(): void
    {
        $stats = $this->validator->getStats('');
        $this->assertFalse($stats['is_valid']);
    }

    public function test_get_stats_is_valid_false_for_too_long(): void
    {
        $validator = new MessageValidator(10);
        $stats = $validator->getStats(str_repeat('a', 50));
        $this->assertFalse($stats['is_valid']);
    }

    public function test_handles_unicode_characters(): void
    {
        $arabicText = 'مرحبا كيف حالك';
        $result = $this->validator->validate($arabicText);

        $this->assertEquals($arabicText, $result);
    }

    public function test_handles_emoji(): void
    {
        $result = $this->validator->validate('Hello 👋 World 🌍');
        $this->assertEquals('Hello 👋 World 🌍', $result);
    }

    public function test_invalid_message_exception_static_constructors(): void
    {
        $empty = InvalidMessageException::empty();
        $this->assertEquals(InvalidMessageException::EMPTY_MESSAGE, $empty->getReason());

        $tooLong = InvalidMessageException::tooLong(100, 150);
        $this->assertEquals(InvalidMessageException::TOO_LONG, $tooLong->getReason());
        $this->assertEquals(100, $tooLong->getMaxLength());
    }

    public function test_fluent_interface(): void
    {
        $result = $this->validator->setMaxLength(100);
        $this->assertSame($this->validator, $result);
    }
}
