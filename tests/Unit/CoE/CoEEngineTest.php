<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\CoE;

use PHPUnit\Framework\TestCase;
use Sisly\CoE\CoEEngine;
use Sisly\DTOs\CoETrace;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\LLM\LLMResponse;
use Sisly\LLM\MockProvider;

class CoEEngineTest extends TestCase
{
    private CoEEngine $engine;
    private MockProvider $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockProvider = new MockProvider();
        $this->engine = new CoEEngine($this->mockProvider);
    }

    public function test_reason_returns_coe_trace(): void
    {
        // Setup mock to return valid JSON
        $this->mockProvider->addResponse('anxiety', json_encode([
            'emotion_primary' => 'anxiety',
            'emotion_secondary' => 'fear',
            'cause_analysis' => 'Upcoming meeting causing stress',
            'user_intent' => 'validation',
            'strategy_selected' => 'validation',
            'draft_response' => 'I hear that you are feeling anxious about the meeting.',
        ]));

        $session = $this->createSession();
        $result = $this->engine->reason($session, 'I am anxious about my meeting', 'System prompt');

        $this->assertInstanceOf(CoETrace::class, $result);
        $this->assertEquals('anxiety', $result->emotionPrimary);
        $this->assertEquals('fear', $result->emotionSecondary);
        $this->assertEquals('validation', $result->userIntent);
        $this->assertEquals('validation', $result->strategySelected);
    }

    public function test_reason_handles_llm_failure(): void
    {
        $this->mockProvider->simulateError('API unavailable');

        $session = $this->createSession();
        $result = $this->engine->reason($session, 'I am anxious', 'System prompt');

        $this->assertInstanceOf(CoETrace::class, $result);
        // Should return fallback trace
        $this->assertEquals('anxiety', $result->emotionPrimary);
        $this->assertEquals('validation', $result->userIntent);
    }

    public function test_reason_handles_invalid_json(): void
    {
        $this->mockProvider->addResponse('test', 'not valid json at all');

        $session = $this->createSession();
        $result = $this->engine->reason($session, 'test message', 'System prompt');

        $this->assertInstanceOf(CoETrace::class, $result);
        // Should return fallback trace
        $this->assertEquals('validation', $result->userIntent);
    }

    public function test_reason_handles_json_in_markdown_code_block(): void
    {
        $response = <<<RESPONSE
```json
{
    "emotion_primary": "overwhelm",
    "emotion_secondary": null,
    "cause_analysis": "Too many tasks",
    "user_intent": "problem-solving",
    "strategy_selected": "technique",
    "draft_response": "Let's focus on one thing at a time."
}
```
RESPONSE;

        $this->mockProvider->addResponse('overwhelmed', $response);

        $session = $this->createSession();
        $result = $this->engine->reason($session, 'I am overwhelmed with work', 'System prompt');

        $this->assertInstanceOf(CoETrace::class, $result);
        $this->assertEquals('overwhelm', $result->emotionPrimary);
        $this->assertEquals('problem-solving', $result->userIntent);
    }

    public function test_reason_validates_user_intent(): void
    {
        $this->mockProvider->addResponse('test', json_encode([
            'emotion_primary' => 'anxiety',
            'emotion_secondary' => null,
            'cause_analysis' => 'Test',
            'user_intent' => 'invalid_intent',
            'strategy_selected' => 'validation',
            'draft_response' => 'Test response',
        ]));

        $session = $this->createSession();
        $result = $this->engine->reason($session, 'test message', 'System prompt');

        // Invalid intent should be normalized to 'validation'
        $this->assertEquals('validation', $result->userIntent);
    }

    public function test_reason_validates_strategy(): void
    {
        $this->mockProvider->addResponse('test', json_encode([
            'emotion_primary' => 'anxiety',
            'emotion_secondary' => null,
            'cause_analysis' => 'Test',
            'user_intent' => 'validation',
            'strategy_selected' => 'invalid_strategy',
            'draft_response' => 'Test response',
        ]));

        $session = $this->createSession();
        $result = $this->engine->reason($session, 'test message', 'System prompt');

        // Invalid strategy should be normalized to 'validation'
        $this->assertEquals('validation', $result->strategySelected);
    }

    public function test_fallback_detects_anxiety_keywords(): void
    {
        $this->mockProvider->simulateError('API error');

        $session = $this->createSession();

        // Test anxiety keywords
        $result = $this->engine->reason($session, 'I feel so nervous', 'System prompt');
        $this->assertEquals('anxiety', $result->emotionPrimary);

        $result = $this->engine->reason($session, 'I am worried about tomorrow', 'System prompt');
        $this->assertEquals('anxiety', $result->emotionPrimary);
    }

    public function test_fallback_detects_anger_keywords(): void
    {
        $this->mockProvider->simulateError('API error');

        $session = $this->createSession();
        $result = $this->engine->reason($session, 'I am so angry at my boss', 'System prompt');

        $this->assertEquals('anger', $result->emotionPrimary);
    }

    public function test_fallback_detects_sadness_keywords(): void
    {
        $this->mockProvider->simulateError('API error');

        $session = $this->createSession();
        $result = $this->engine->reason($session, 'I feel so sad today', 'System prompt');

        $this->assertEquals('sadness', $result->emotionPrimary);
    }

    public function test_fallback_detects_overwhelm_keywords(): void
    {
        $this->mockProvider->simulateError('API error');

        $session = $this->createSession();
        $result = $this->engine->reason($session, 'I am feeling overwhelmed', 'System prompt');

        $this->assertEquals('overwhelm', $result->emotionPrimary);
    }

    public function test_fallback_detects_doubt_keywords(): void
    {
        $this->mockProvider->simulateError('API error');

        $session = $this->createSession();
        $result = $this->engine->reason($session, 'I doubt myself constantly, imposter feelings', 'System prompt');

        $this->assertEquals('doubt', $result->emotionPrimary);
    }

    public function test_fallback_returns_distress_for_unknown(): void
    {
        $this->mockProvider->simulateError('API error');

        $session = $this->createSession();
        $result = $this->engine->reason($session, 'hello there', 'System prompt');

        $this->assertEquals('distress', $result->emotionPrimary);
    }

    public function test_validate_alignment_for_validation_strategy(): void
    {
        $trace = new CoETrace(
            emotionPrimary: 'anxiety',
            emotionSecondary: null,
            causeAnalysis: 'Test',
            userIntent: 'validation',
            strategySelected: 'validation',
            draftResponse: 'Test',
        );

        // Validation responses should acknowledge/understand
        $this->assertTrue($this->engine->validateAlignment($trace, 'I hear that you are struggling'));
        $this->assertTrue($this->engine->validateAlignment($trace, 'I understand how you feel'));
    }

    public function test_validate_alignment_for_exploration_strategy(): void
    {
        $trace = new CoETrace(
            emotionPrimary: 'anxiety',
            emotionSecondary: null,
            causeAnalysis: 'Test',
            userIntent: 'understanding',
            strategySelected: 'exploration',
            draftResponse: 'Test',
        );

        // Exploration responses should ask questions
        $this->assertTrue($this->engine->validateAlignment($trace, 'Can you tell me more about that?'));
        $this->assertFalse($this->engine->validateAlignment($trace, 'I understand completely'));
    }

    public function test_validate_alignment_for_technique_strategy(): void
    {
        $trace = new CoETrace(
            emotionPrimary: 'anxiety',
            emotionSecondary: null,
            causeAnalysis: 'Test',
            userIntent: 'problem-solving',
            strategySelected: 'technique',
            draftResponse: 'Test',
        );

        // Technique responses should mention time/breath
        $this->assertTrue($this->engine->validateAlignment($trace, 'Take a slow breath in'));
        $this->assertTrue($this->engine->validateAlignment($trace, 'Give me 30 seconds'));
    }

    private function createSession(): Session
    {
        return Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(),
        );
    }
}
