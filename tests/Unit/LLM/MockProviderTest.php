<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\LLM;

use PHPUnit\Framework\TestCase;
use Sisly\LLM\MockProvider;

class MockProviderTest extends TestCase
{
    private MockProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new MockProvider();
    }

    // ==================== BASIC OPERATIONS ====================

    public function test_is_available_by_default(): void
    {
        $this->assertTrue($this->provider->isAvailable());
    }

    public function test_get_name(): void
    {
        $this->assertEquals('mock', $this->provider->getName());
    }

    public function test_generate_returns_response(): void
    {
        $response = $this->provider->generate('Hello');

        $this->assertTrue($response->success);
        $this->assertNotEmpty($response->content);
    }

    public function test_chat_returns_response(): void
    {
        $response = $this->provider->chat([
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $this->assertTrue($response->success);
        $this->assertNotEmpty($response->content);
    }

    // ==================== PRESET RESPONSES ====================

    public function test_add_response_for_pattern(): void
    {
        $this->provider->addResponse('test pattern', 'preset response');

        $response = $this->provider->generate('This is a test pattern message');

        $this->assertEquals('preset response', $response->content);
    }

    public function test_clear_responses(): void
    {
        $this->provider->addResponse('test', 'preset');
        $this->provider->clearResponses();

        $response = $this->provider->generate('test');

        $this->assertNotEquals('preset', $response->content);
    }

    // ==================== SIMULATE UNAVAILABLE ====================

    public function test_simulate_unavailable(): void
    {
        $this->provider->simulateUnavailable(true);

        $this->assertFalse($this->provider->isAvailable());
    }

    public function test_restore_availability(): void
    {
        $this->provider->simulateUnavailable(true);
        $this->provider->simulateUnavailable(false);

        $this->assertTrue($this->provider->isAvailable());
    }

    // ==================== SIMULATE ERROR ====================

    public function test_simulate_error(): void
    {
        $this->provider->simulateError('Connection failed');

        $response = $this->provider->generate('Hello');

        $this->assertFalse($response->success);
        $this->assertEquals('Connection failed', $response->error);
    }

    public function test_clear_simulated_error(): void
    {
        $this->provider->simulateError('Error');
        $this->provider->simulateError(null);

        $response = $this->provider->generate('Hello');

        $this->assertTrue($response->success);
    }

    // ==================== CALL HISTORY ====================

    public function test_tracks_call_history(): void
    {
        $this->provider->generate('First call');
        $this->provider->generate('Second call');

        $this->assertEquals(2, $this->provider->getCallCount());
    }

    public function test_get_call_history(): void
    {
        $this->provider->generate('Test prompt');

        $history = $this->provider->getCallHistory();

        $this->assertCount(1, $history);
        $this->assertEquals('Test prompt', $history[0]['prompt']);
    }

    public function test_clear_history(): void
    {
        $this->provider->generate('Test');
        $this->provider->clearHistory();

        $this->assertEquals(0, $this->provider->getCallCount());
    }

    // ==================== DISPATCHER RESPONSE ====================

    public function test_generates_dispatcher_response_for_classify_prompt(): void
    {
        $response = $this->provider->generate('Please classify this message about meetings');

        $data = json_decode($response->content, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('coach', $data);
        $this->assertArrayHasKey('confidence', $data);
        $this->assertArrayHasKey('reasoning', $data);
    }

    public function test_routes_meeting_keywords_to_meetly(): void
    {
        $response = $this->provider->generate('dispatcher: I have a presentation meeting');

        $data = json_decode($response->content, true);

        $this->assertEquals('meetly', $data['coach']);
    }

    public function test_routes_anger_keywords_to_vento(): void
    {
        $response = $this->provider->generate('dispatcher: I am angry and frustrated');

        $data = json_decode($response->content, true);

        $this->assertEquals('vento', $data['coach']);
    }

    // ==================== COACHING RESPONSE ====================

    public function test_generates_coaching_response_for_anxious(): void
    {
        $response = $this->provider->generate('I feel anxious about tomorrow');

        $this->assertStringContainsString('anxious', strtolower($response->content));
    }

    public function test_generates_coaching_response_for_stressed(): void
    {
        $response = $this->provider->generate('I am stressed and overwhelmed');

        $this->assertStringContainsString('overwhelm', strtolower($response->content));
    }

    // ==================== CHAT METHOD ====================

    public function test_chat_tracks_messages_in_history(): void
    {
        $this->provider->chat([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there'],
            ['role' => 'user', 'content' => 'I need help'],
        ], 'System prompt');

        $history = $this->provider->getCallHistory();

        $this->assertArrayHasKey('messages', $history[0]);
        $this->assertArrayHasKey('systemPrompt', $history[0]);
    }

    public function test_chat_uses_preset_response(): void
    {
        $this->provider->addResponse('specific question', 'specific answer');

        $response = $this->provider->chat([
            ['role' => 'user', 'content' => 'This is a specific question'],
        ]);

        $this->assertEquals('specific answer', $response->content);
    }

    // ==================== TOKEN COUNTS ====================

    public function test_response_includes_token_counts(): void
    {
        $response = $this->provider->generate('Hello');

        $this->assertNotNull($response->promptTokens);
        $this->assertNotNull($response->completionTokens);
        $this->assertEquals('mock-model', $response->model);
    }

    public function test_get_total_tokens(): void
    {
        $response = $this->provider->generate('Hello');

        $total = $response->getTotalTokens();

        $this->assertNotNull($total);
        $this->assertEquals(
            $response->promptTokens + $response->completionTokens,
            $total
        );
    }

    // ==================== METHOD CHAINING ====================

    public function test_fluent_interface(): void
    {
        $result = $this->provider
            ->addResponse('test', 'response')
            ->simulateUnavailable(false)
            ->clearHistory();

        $this->assertInstanceOf(MockProvider::class, $result);
    }
}
