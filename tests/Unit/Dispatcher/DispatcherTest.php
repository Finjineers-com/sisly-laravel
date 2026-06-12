<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Sisly\Dispatcher\Dispatcher;
use Sisly\Enums\CoachId;
use Sisly\LLM\MockProvider;

class DispatcherTest extends TestCase
{
    private MockProvider $mockLlm;
    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockLlm = new MockProvider();
        $this->dispatcher = new Dispatcher($this->mockLlm);
    }

    // ==================== CLASSIFICATION ====================

    public function test_classify_returns_dispatcher_result(): void
    {
        $result = $this->dispatcher->classify('I have a meeting tomorrow');

        $this->assertTrue($result->success);
        $this->assertInstanceOf(CoachId::class, $result->coach);
        $this->assertGreaterThan(0, $result->confidence);
    }

    public function test_classify_routes_meeting_anxiety_to_meetly(): void
    {
        $result = $this->dispatcher->classify('I have a big presentation tomorrow and I am nervous');

        $this->assertEquals(CoachId::MEETLY, $result->coach);
    }

    public function test_classify_routes_anger_to_vento(): void
    {
        $result = $this->dispatcher->classify('I am so angry and frustrated with my coworker');

        $this->assertEquals(CoachId::VENTO, $result->coach);
    }

    public function test_classify_routes_rumination_to_loopy(): void
    {
        $result = $this->dispatcher->classify('I keep thinking about the same thing over and over');

        $this->assertEquals(CoachId::LOOPY, $result->coach);
    }

    public function test_classify_routes_overwhelm_to_presso(): void
    {
        $result = $this->dispatcher->classify('I have too much to do and the pressure is overwhelming');

        $this->assertEquals(CoachId::PRESSO, $result->coach);
    }

    public function test_classify_routes_self_doubt_to_boostly(): void
    {
        $result = $this->dispatcher->classify('I doubt myself and feel like an imposter at work');

        $this->assertEquals(CoachId::BOOSTLY, $result->coach);
    }

    // ==================== CONFIDENCE ====================

    public function test_result_meets_threshold(): void
    {
        $this->mockLlm->addResponse('meeting', json_encode([
            'coach' => 'meetly',
            'confidence' => 0.9,
            'reasoning' => 'High confidence match',
        ]));

        $result = $this->dispatcher->classify('I have a meeting');

        $this->assertTrue($result->meetsThreshold(0.7));
        $this->assertTrue($result->meetsThreshold(0.9));
        $this->assertFalse($result->meetsThreshold(0.95));
    }

    public function test_get_confidence_threshold(): void
    {
        $this->assertEquals(0.7, $this->dispatcher->getConfidenceThreshold());
    }

    // ==================== ERROR HANDLING ====================

    public function test_classify_handles_llm_error(): void
    {
        $this->mockLlm->simulateError('API connection failed');

        $result = $this->dispatcher->classify('Hello');

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        // Should fall back to default coach
        $this->assertEquals(CoachId::MEETLY, $result->coach);
    }

    public function test_classify_handles_invalid_json(): void
    {
        $this->mockLlm->addResponse('test', 'not valid json');

        $result = $this->dispatcher->classify('test message');

        // Should attempt to parse and fall back
        $this->assertTrue($result->success);
        $this->assertInstanceOf(CoachId::class, $result->coach);
    }

    public function test_classify_handles_invalid_coach(): void
    {
        $this->mockLlm->addResponse('test', json_encode([
            'coach' => 'invalid_coach',
            'confidence' => 0.9,
            'reasoning' => 'Test',
        ]));

        $result = $this->dispatcher->classify('test');

        // Should fall back to default
        $this->assertFalse($result->success);
        $this->assertEquals(CoachId::MEETLY, $result->coach);
    }

    // ==================== RECLASSIFY ====================

    public function test_reclassify_suggests_handoff_for_different_coach(): void
    {
        // Simulate a clear vento match when currently with meetly
        $this->mockLlm->addResponse('angry', json_encode([
            'coach' => 'vento',
            'confidence' => 0.9,
            'reasoning' => 'Clear anger pattern',
        ]));

        $result = $this->dispatcher->reclassify(
            'I am very angry right now',
            CoachId::MEETLY
        );

        $this->assertEquals(CoachId::VENTO, $result->coach);
    }

    public function test_reclassify_stays_with_current_for_low_confidence(): void
    {
        $this->mockLlm->addResponse('maybe', json_encode([
            'coach' => 'vento',
            'confidence' => 0.5,
            'reasoning' => 'Low confidence',
        ]));

        $result = $this->dispatcher->reclassify('maybe angry', CoachId::MEETLY);

        $this->assertEquals(CoachId::MEETLY, $result->coach);
    }

    public function test_reclassify_stays_with_current_for_same_coach(): void
    {
        $this->mockLlm->addResponse('meeting', json_encode([
            'coach' => 'meetly',
            'confidence' => 0.9,
            'reasoning' => 'Same coach',
        ]));

        $result = $this->dispatcher->reclassify('meeting anxiety', CoachId::MEETLY);

        $this->assertEquals(CoachId::MEETLY, $result->coach);
        $this->assertEquals(1.0, $result->confidence);
    }

    // ==================== ENABLED COACHES ====================

    public function test_uses_enabled_coaches_from_config(): void
    {
        $dispatcher = new Dispatcher($this->mockLlm, [
            'enabled_coaches' => ['meetly', 'vento'],
        ]);

        $this->mockLlm->addResponse('doubt', json_encode([
            'coach' => 'boostly',
            'confidence' => 0.9,
            'reasoning' => 'Self doubt',
        ]));

        $result = $dispatcher->classify('I have self doubt');

        // Boostly is not enabled, should fall back to default
        $this->assertEquals(CoachId::MEETLY, $result->coach);
    }

    // ==================== CUSTOM PROMPT ====================

    public function test_set_custom_prompt(): void
    {
        $customPrompt = 'Custom dispatcher prompt: {{USER_MESSAGE}}';
        $this->dispatcher->setPrompt($customPrompt);

        $this->dispatcher->classify('test message');

        $history = $this->mockLlm->getCallHistory();
        $this->assertCount(1, $history);
        $this->assertStringContainsString('Custom dispatcher prompt', $history[0]['prompt']);
    }

    // ==================== RESULT TO ARRAY ====================

    public function test_result_to_array(): void
    {
        $result = $this->dispatcher->classify('meeting anxiety');

        $array = $result->toArray();

        $this->assertArrayHasKey('coach', $array);
        $this->assertArrayHasKey('confidence', $array);
        $this->assertArrayHasKey('reasoning', $array);
        $this->assertArrayHasKey('success', $array);
    }
}
