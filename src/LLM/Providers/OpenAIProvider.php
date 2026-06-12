<?php

declare(strict_types=1);

namespace Sisly\LLM\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\LLM\LLMResponse;

/**
 * OpenAI API provider for LLM completions.
 *
 * Supports GPT-4, GPT-4-turbo, GPT-3.5-turbo models.
 * Uses Guzzle directly for compatibility outside Laravel context.
 */
class OpenAIProvider implements LLMProviderInterface
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    private const DEFAULT_MODEL = 'gpt-4-turbo';
    private const DEFAULT_TIMEOUT = 30;
    private const DEFAULT_MAX_TOKENS = 150;
    private const DEFAULT_TEMPERATURE = 0.7;

    private string $apiKey;
    private string $model;
    private int $timeout;
    private int $maxRetries;
    private int $retryDelay;
    private Client $client;

    /**
     * @param array<string, mixed> $config
     * @param Client|null $client Optional Guzzle client for testing
     */
    public function __construct(array $config = [], ?Client $client = null)
    {
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? self::DEFAULT_MODEL;
        $this->timeout = $config['timeout'] ?? self::DEFAULT_TIMEOUT;
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->retryDelay = $config['retry_delay'] ?? 1000; // milliseconds
        $this->client = $client ?? new Client([
            'timeout' => $this->timeout,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Generate a completion from OpenAI.
     */
    public function generate(string $prompt, array $options = []): LLMResponse
    {
        return $this->chat(
            messages: [['role' => 'user', 'content' => $prompt]],
            systemPrompt: '',
            options: $options,
        );
    }

    /**
     * Generate a completion with conversation history.
     */
    public function chat(array $messages, string $systemPrompt = '', array $options = []): LLMResponse
    {
        if (!$this->isAvailable()) {
            return LLMResponse::failure('OpenAI API key not configured');
        }

        $formattedMessages = $this->formatMessages($messages, $systemPrompt);

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $formattedMessages,
            'max_tokens' => $options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS,
            'temperature' => $options['temperature'] ?? self::DEFAULT_TEMPERATURE,
        ];

        // Add optional parameters
        if (isset($options['top_p'])) {
            $payload['top_p'] = $options['top_p'];
        }
        if (isset($options['presence_penalty'])) {
            $payload['presence_penalty'] = $options['presence_penalty'];
        }
        if (isset($options['frequency_penalty'])) {
            $payload['frequency_penalty'] = $options['frequency_penalty'];
        }

        return $this->executeWithRetry($payload);
    }

    /**
     * Check if the provider is available.
     */
    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Get the provider name.
     */
    public function getName(): string
    {
        return 'openai';
    }

    /**
     * Format messages for the OpenAI API.
     *
     * @param array<array{role: string, content: string}> $messages
     * @return array<array{role: string, content: string}>
     */
    private function formatMessages(array $messages, string $systemPrompt): array
    {
        $formatted = [];

        // Add system prompt if provided
        if (!empty($systemPrompt)) {
            $formatted[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        // Add conversation messages
        foreach ($messages as $message) {
            $formatted[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        return $formatted;
    }

    /**
     * Execute the API request with retry logic.
     *
     * @param array<string, mixed> $payload
     */
    private function executeWithRetry(array $payload): LLMResponse
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = $this->makeRequest($payload);
                $statusCode = $response['status'];
                $body = $response['body'];

                if ($statusCode >= 200 && $statusCode < 300) {
                    return $this->parseResponse($body);
                }

                // Handle rate limiting
                if ($statusCode === 429) {
                    $retryAfter = $response['headers']['Retry-After'][0] ?? null;
                    $delay = $retryAfter ? (int) $retryAfter * 1000 : $this->getBackoffDelay($attempt);
                    usleep($delay * 1000);
                    continue;
                }

                // Handle server errors (5xx) with retry
                if ($statusCode >= 500) {
                    $lastError = "OpenAI server error: {$statusCode}";
                    usleep($this->getBackoffDelay($attempt) * 1000);
                    continue;
                }

                // Client errors (4xx) don't retry
                $errorMessage = $body['error']['message'] ?? "HTTP {$statusCode}";
                return LLMResponse::failure("OpenAI error: {$errorMessage}");

            } catch (ConnectException $e) {
                $lastError = "Connection failed: " . $e->getMessage();
                if ($attempt < $this->maxRetries) {
                    usleep($this->getBackoffDelay($attempt) * 1000);
                }
            } catch (RequestException $e) {
                $lastError = $e->getMessage();
                if ($attempt < $this->maxRetries) {
                    usleep($this->getBackoffDelay($attempt) * 1000);
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempt < $this->maxRetries) {
                    usleep($this->getBackoffDelay($attempt) * 1000);
                }
            }
        }

        return LLMResponse::failure("OpenAI request failed after {$this->maxRetries} attempts: {$lastError}");
    }

    /**
     * Make the HTTP request to OpenAI using Guzzle.
     *
     * @param array<string, mixed> $payload
     * @return array{status: int, body: array<string, mixed>, headers: array<string, array<string>>}
     */
    private function makeRequest(array $payload): array
    {
        $response = $this->client->post(self::API_URL, [
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'http_errors' => false, // Don't throw on 4xx/5xx
        ]);

        $body = json_decode($response->getBody()->getContents(), true) ?? [];

        return [
            'status' => $response->getStatusCode(),
            'body' => $body,
            'headers' => $response->getHeaders(),
        ];
    }

    /**
     * Parse the OpenAI response.
     *
     * @param array<string, mixed> $data
     */
    private function parseResponse(array $data): LLMResponse
    {
        $choices = $data['choices'] ?? [];

        if (empty($choices)) {
            return LLMResponse::failure('OpenAI returned no choices');
        }

        $choice = $choices[0];
        $content = $choice['message']['content'] ?? '';
        $finishReason = $choice['finish_reason'] ?? null;

        $usage = $data['usage'] ?? [];
        $promptTokens = $usage['prompt_tokens'] ?? null;
        $completionTokens = $usage['completion_tokens'] ?? null;

        $model = $data['model'] ?? $this->model;

        return LLMResponse::success(
            content: trim($content),
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            model: $model,
            finishReason: $finishReason,
        );
    }

    /**
     * Calculate exponential backoff delay.
     */
    private function getBackoffDelay(int $attempt): int
    {
        // Exponential backoff: delay * 2^(attempt-1)
        // With jitter: +/- 10%
        $baseDelay = $this->retryDelay * pow(2, $attempt - 1);
        $jitter = $baseDelay * 0.1 * (mt_rand(-100, 100) / 100);

        return (int) ($baseDelay + $jitter);
    }

    /**
     * Set a new API key (useful for testing).
     */
    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Get current model.
     */
    public function getModel(): string
    {
        return $this->model;
    }
}
