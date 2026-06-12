<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\LLM\Providers;

use Illuminate\Support\Facades\Http;
use Sisly\LLM\Providers\GeminiProvider;
use Sisly\Tests\TestCase;

class GeminiProviderTest extends TestCase
{
    private GeminiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new GeminiProvider([
            'api_key' => 'test-api-key',
            'model' => 'gemini-pro',
            'timeout' => 30,
            'max_retries' => 1,
        ]);
    }

    public function test_get_name_returns_gemini(): void
    {
        $this->assertEquals('gemini', $this->provider->getName());
    }

    public function test_is_available_returns_true_when_api_key_set(): void
    {
        $this->assertTrue($this->provider->isAvailable());
    }

    public function test_is_available_returns_false_when_api_key_empty(): void
    {
        $provider = new GeminiProvider(['api_key' => '']);
        $this->assertFalse($provider->isAvailable());
    }

    public function test_generate_returns_failure_when_not_configured(): void
    {
        $provider = new GeminiProvider(['api_key' => '']);
        $response = $provider->generate('Hello');

        $this->assertFalse($response->success);
        $this->assertStringContainsString('not configured', $response->error);
    }

    public function test_generate_sends_correct_request(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => 'Test response'],
                            ],
                        ],
                        'finishReason' => 'STOP',
                    ],
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 10,
                    'candidatesTokenCount' => 5,
                ],
            ], 200),
        ]);

        $response = $this->provider->generate('Hello');

        $this->assertTrue($response->success);
        $this->assertEquals('Test response', $response->content);
        $this->assertEquals(10, $response->promptTokens);
        $this->assertEquals(5, $response->completionTokens);
    }

    public function test_chat_formats_messages_correctly(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => ['parts' => [['text' => 'Response']]],
                        'finishReason' => 'STOP',
                    ],
                ],
            ], 200),
        ]);

        $messages = [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there'],
            ['role' => 'user', 'content' => 'How are you?'],
        ];

        $response = $this->provider->chat($messages, 'You are a coach');

        $this->assertTrue($response->success);

        // Verify request was made with correct format
        Http::assertSent(function ($request) {
            $body = $request->data();
            $contents = $body['contents'] ?? [];

            // First message should have system prompt prepended
            // Roles should be converted: assistant -> model
            return count($contents) === 3 &&
                   $contents[0]['role'] === 'user' &&
                   str_contains($contents[0]['parts'][0]['text'], 'You are a coach') &&
                   $contents[1]['role'] === 'model';
        });
    }

    public function test_handles_rate_limiting(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'Rate limited']], 429)
                ->push([
                    'candidates' => [
                        ['content' => ['parts' => [['text' => 'Success']]]],
                    ],
                ], 200),
        ]);

        $provider = new GeminiProvider([
            'api_key' => 'test-key',
            'max_retries' => 2,
            'retry_delay' => 10,
        ]);

        $response = $provider->generate('Test');

        $this->assertTrue($response->success);
        $this->assertEquals('Success', $response->content);
    }

    public function test_handles_safety_blocked_prompt(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'promptFeedback' => [
                    'blockReason' => 'SAFETY',
                ],
                'candidates' => [],
            ], 200),
        ]);

        $response = $this->provider->generate('Test');

        $this->assertFalse($response->success);
        $this->assertStringContainsString('blocked', $response->error);
    }

    public function test_handles_safety_blocked_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'finishReason' => 'SAFETY',
                        'content' => ['parts' => []],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->provider->generate('Test');

        $this->assertFalse($response->success);
        $this->assertStringContainsString('safety filter', $response->error);
    }

    public function test_handles_empty_candidates(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [],
            ], 200),
        ]);

        $response = $this->provider->generate('Test');

        $this->assertFalse($response->success);
        $this->assertStringContainsString('no candidates', $response->error);
    }

    public function test_handles_server_error_with_retry(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'Server error']], 500)
                ->push([
                    'candidates' => [
                        ['content' => ['parts' => [['text' => 'Success']]]],
                    ],
                ], 200),
        ]);

        $provider = new GeminiProvider([
            'api_key' => 'test-key',
            'max_retries' => 2,
            'retry_delay' => 10,
        ]);

        $response = $provider->generate('Test');

        $this->assertTrue($response->success);
    }

    public function test_handles_client_error_without_retry(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'Invalid API key'],
            ], 400),
        ]);

        $response = $this->provider->generate('Test');

        $this->assertFalse($response->success);
        $this->assertStringContainsString('Invalid API key', $response->error);
    }

    public function test_respects_temperature_option(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Response']]]],
                ],
            ], 200),
        ]);

        $this->provider->generate('Test', ['temperature' => 0.0]);

        Http::assertSent(function ($request) {
            return $request->data()['generationConfig']['temperature'] === 0.0;
        });
    }

    public function test_respects_max_tokens_option(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Response']]]],
                ],
            ], 200),
        ]);

        $this->provider->generate('Test', ['max_tokens' => 500]);

        Http::assertSent(function ($request) {
            return $request->data()['generationConfig']['maxOutputTokens'] === 500;
        });
    }

    public function test_includes_safety_settings(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Response']]]],
                ],
            ], 200),
        ]);

        $this->provider->generate('Test');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return isset($body['safetySettings']) && count($body['safetySettings']) > 0;
        });
    }

    public function test_get_model_returns_configured_model(): void
    {
        $this->assertEquals('gemini-pro', $this->provider->getModel());
    }

    public function test_set_api_key_updates_key(): void
    {
        $provider = new GeminiProvider(['api_key' => '']);
        $this->assertFalse($provider->isAvailable());

        $provider->setApiKey('new-key');
        $this->assertTrue($provider->isAvailable());
    }
}
