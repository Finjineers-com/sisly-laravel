<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Coaches;

use Sisly\Coaches\PrescriptionParser;
use Sisly\DTOs\Prescription;
use Sisly\Enums\ContentType;
use Sisly\Tests\TestCase;

class PrescriptionParserTest extends TestCase
{
    private PrescriptionParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PrescriptionParser();
    }

    public function test_it_parses_valid_prescription_block(): void
    {
        $text = "Take a deep breath and relax.\n\n```sisly\n{\n  \"content_type\": \"Quiet mind\",\n  \"reason\": \"Here is a sound to help you settle down.\"\n}\n```";

        $result = $this->parser->parse($text);

        $this->assertEquals("Take a deep breath and relax.", $result['text']);
        $this->assertInstanceOf(Prescription::class, $result['prescription']);
        $this->assertEquals(ContentType::QUIET_MIND, $result['prescription']->contentType);
        $this->assertEquals("Here is a sound to help you settle down.", $result['prescription']->reason);
    }

    public function test_it_ignores_invalid_json(): void
    {
        $text = "Take a deep breath.\n\n```sisly\n{\n  \"content_type\": \"Meetings\"\n  \"reason\": \"unquoted or malformed\"\n}\n```";

        $result = $this->parser->parse($text);

        $this->assertEquals($text, $result['text']);
        $this->assertNull($result['prescription']);
    }

    public function test_it_ignores_invalid_content_type(): void
    {
        $text = "Take a deep breath.\n\n```sisly\n{\n  \"content_type\": \"NotAValidType\",\n  \"reason\": \"Invalid type\"\n}\n```";

        $result = $this->parser->parse($text);

        $this->assertEquals($text, $result['text']);
        $this->assertNull($result['prescription']);
    }

    public function test_it_handles_no_block(): void
    {
        $text = "Take a deep breath and relax.";

        $result = $this->parser->parse($text);

        $this->assertEquals($text, $result['text']);
        $this->assertNull($result['prescription']);
    }
}
