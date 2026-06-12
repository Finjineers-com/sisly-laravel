<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\LLM\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Middleware;
use Sisly\LLM\Providers\OpenAIProvider;
use Sisly\Tests\TestCase;

class OpenAIProviderTest extends TestCase
{
    private OpenAIProvider $provider;
    private array $requestHistory = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestHistory = [];
        $this->provider = new OpenAIProvider([
            'api_key' => 'test-api-key',
            'model' => 'gpt-4-turbo',
            'timeout' => 30,
            'max_retries' => 1, // Reduce retries for faster tests
        ]);
    }

    private function createMockClient(array $responses): Client
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);

        // Add history middleware to track requests
        $history = Middleware::history($this->requestHistory);
        $handlerStack->push($history);

        return new Client(['handler' => $handlerStack]);
    }

    private function createSuccessResponse(string $content = 'Test response'): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [
                [
                    'message' => ['content' => $content],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
            ],
            'model' => 'gpt-4-turbo',
        ]));
    }

    public function test_get_name_returns_openai(): void
    {
        $this->assertEquals('openai', $this->provider->getName());
    }

    public function test_is_available_returns_true_when_api_key_set(): void
    {
        $this->assertTrue($this->provider->isAvailable());
    }

    public function test_is_available_returns_false_when_api_key_empty(): void
    {
        $provider = new OpenAIProvider(['api_key' => '']);
        $this->assertFalse($provider->isAvailable());
    }

    public function test_generate_returns_failure_when_not_configured(): void
    {
        $provider = new OpenAIProvider(['api_key' => '']);
        $response = $provider->generate('Hello');

        $this->assertFalse($response->success);
        $this->assertStringContainsString('not configured', $response->error);
    }

    public function test_generate_sends_correct_request(): void
    {
        $client = $this->createMockClient([
            $this->createSuccessResponse('Test response'),
        ]);

        $provider = new OpenAIProvider([
            'api_key' => 'test-api-key',
            'model' => 'gpt-4-turbo',
        ], $client);

        $response = $provider->generate('Hello');

        $this->assertTrue($response->success);
        $this->assertEquals('Test response', $response->content);
        $this->assertEquals(10, $response->promptTokens);
        $this->assertEquals(5, $response->completionTokens);
        $this->assertEquals('gpt-4-turbo', $response->model);
    }

    public function test_chat_sends_messages_with_system_prompt(): void
    {
        $client = $this->createMockClient([
            $this->createSuccessResponse('Response'),
        ]);

        $provider = new OpenAIProvider([
            'api_key' => 'test-api-key',
            'model' => 'gpt-4-turbo',
        ], $client);

        $messages = [
            ['role' => 'user', 'content' => 'I am anxious'],
        ];

        $response = $provider->chat($messages, 'You are a coach');

        $this->assertTrue($response->success);

        // Verify request was made with correct format
        $this->assertCount(1, $this->requestHistory);
        $request = $this->requestHistory[0]['request'];
        $body = json_decode($request->getBody()->getContents(), true);

        $this->assertEquals('system', $body['messages'][0]['role']);
        $this->assertEquals('You are a coach', $body['messages'][0]['content']);
    }

    public function test_handles_rate_limiting(): void
    {
        $client = $this->createMockClient([
            new Response(429, ['Retry-After' => '1'], json_encode(['error' => ['message' => 'Rate limited']])),
            $this->createSuccessResponse('Success'),
        ]);

        $provider = new OpenAIProvider([
            'api_key' => 'test-key',
            'max_retries' => 2,
            'retry_delay' => 10,
        ], $client);

        $response = $provider->generate('Test');

        $this->assertTrue($response->success);
        $this->assertEquals('Success', $response->content);
    }

    public function test_handles_server_error_with_retry(): void
    {
        $client = $this->createMockClient([
            new Response(500, [], json_encode(['error' => ['message' => 'Server error']])),
            $this->createSuccessResponse('Success'),
        ]);

        $provider = new OpenAIProvider([
            'api_key' => 'test-key',
            'max_retries' => 2,
            'retry_delay' => 10,
        ], $client);

        $response = $provider->generate('Test');

        $this->assertTrue($response->success);
    }

    public function test_handles_client_error_without_retry(): void
    {
        $client = $this->createMockClient([
            new Response(401, [], json_encode(['error' => ['message' => 'Invalid API key']])),
        ]);

        $provider = new OpenAIProvider([
            'api_key' => 'test-api-key',
        ], $client);

        $response = $provider->generate('Test');

        $this->assertFalse($response->success);
        $this->assertStringContainsString('Invalid API key', $response->error);
    }

    public function test_handles_empty_choices(): void
    {
        $client = $this->createMockClient([
            new Response(200, [], json_encode([
                'choices' => [],
                'model' => 'gpt-4-turbo',
            ])),
        ]);

        $provider = new OpenAIProvider([
            'api_key' => 'test-api-key',
        ], $client);

        $response = $provider->generate('Test');

        $this->assertFalse($response->success);
        $this->assertStringContainsString('no choices', $response->error);
    }

    public function test_respects_temperature_option(): void
    {
        $client = $this->createMockClient([
            $this->createSuccessResponse('Response'),
        ]);

        $provider = new OpenAIProvider([
            'api_key' => 'test-api-key',
        ], $client);

        $provider->generate('Test', ['temperature' => 0.0]);

        $this->assertCount(1, $this->requestHistory);
        $request = $this->requestHistory[0]['request'];
        $body = json_decode($request->getBody()->getContents(), true);

        $this->assertEquals(0.0, $body['temperature']);
    }

    public function test_respects_max_tokens_option(): void
    {
        $client = $this->createMockClient([
            $this->createSuccessResponse('Response'),
        ]);

        $provider = new OpenAIProvider([
            'api_key' => 'test-api-key',
        ], $client);

        $provider->generate('Test', ['max_tokens' => 500]);

        $this->assertCount(1, $this->requestHistory);
        $request = $this->requestHistory[0]['request'];
        $body = json_decode($request->getBody()->getContents(), true);

        $this->assertEquals(500, $body['max_tokens']);
    }

    public function test_get_model_returns_configured_model(): void
    {
        $this->assertEquals('gpt-4-turbo', $this->provider->getModel());
    }

    public function test_set_api_key_updates_key(): void
    {
        $provider = new OpenAIProvider(['api_key' => '']);
        $this->assertFalse($provider->isAvailable());

        $provider->setApiKey('new-key');
        $this->assertTrue($provider->isAvailable());
    }
}
