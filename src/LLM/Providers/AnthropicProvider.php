<?php

declare(strict_types=1);

namespace Sisly\LLM\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\LLM\LLMResponse;

/**
 * Anthropic API provider for LLM completions.
 *
 * Supports Claude 3.5 Sonnet, Claude 3 Opus, etc.
 * Uses Guzzle directly for compatibility outside Laravel context.
 */
class AnthropicProvider implements LLMProviderInterface
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const DEFAULT_MODEL = 'claude-3-5-sonnet-latest';
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
     * Generate a completion from Anthropic.
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
            return LLMResponse::failure('Anthropic API key not configured');
        }

        $formattedMessages = $this->formatMessages($messages);

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $formattedMessages,
            'max_tokens' => $options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS,
            'temperature' => $options['temperature'] ?? self::DEFAULT_TEMPERATURE,
        ];

        // Add optional system prompt at top-level
        if (!empty($systemPrompt)) {
            $payload['system'] = $systemPrompt;
        }

        // Add optional parameters if set
        if (isset($options['top_p'])) {
            $payload['top_p'] = $options['top_p'];
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
        return 'anthropic';
    }

    /**
     * Format messages for the Anthropic API.
     *
     * @param array<array{role: string, content: string}> $messages
     * @return array<array{role: string, content: string}>
     */
    private function formatMessages(array $messages): array
    {
        $formatted = [];

        foreach ($messages as $message) {
            $role = $message['role'];
            if ($role === 'system') {
                continue; // Handled separately in 'system' prompt key
            }
            $formatted[] = [
                'role' => $role,
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
                    $lastError = "Rate limit exceeded (HTTP 429)";
                    $retryAfter = $response['headers']['Retry-After'][0] ?? null;
                    $delay = $retryAfter ? (int) $retryAfter * 1000 : $this->getBackoffDelay($attempt);
                    usleep($delay * 1000);
                    continue;
                }

                // Handle server errors (5xx) with retry
                if ($statusCode >= 500) {
                    $lastError = "Anthropic server error: {$statusCode}";
                    usleep($this->getBackoffDelay($attempt) * 1000);
                    continue;
                }

                // Client errors (4xx) don't retry
                $errorMessage = $body['error']['message'] ?? "HTTP {$statusCode}";
                return LLMResponse::failure("Anthropic error: {$errorMessage}");

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

        return LLMResponse::failure("Anthropic request failed after {$this->maxRetries} attempts: {$lastError}");
    }

    /**
     * Make the HTTP request to Anthropic using Guzzle.
     *
     * @param array<string, mixed> $payload
     * @return array{status: int, body: array<string, mixed>, headers: array<string, array<string>>}
     */
    private function makeRequest(array $payload): array
    {
        $response = $this->client->post(self::API_URL, [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
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
     * Parse the Anthropic response.
     *
     * @param array<string, mixed> $data
     */
    private function parseResponse(array $data): LLMResponse
    {
        $contentArray = $data['content'] ?? [];

        if (empty($contentArray)) {
            return LLMResponse::failure('Anthropic returned empty content');
        }

        // Search for text block in response
        $content = '';
        foreach ($contentArray as $item) {
            if (isset($item['type']) && $item['type'] === 'text') {
                $content = $item['text'] ?? '';
                break;
            }
        }

        $finishReason = $data['stop_reason'] ?? null;

        $usage = $data['usage'] ?? [];
        $promptTokens = $usage['input_tokens'] ?? null;
        $completionTokens = $usage['output_tokens'] ?? null;

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
