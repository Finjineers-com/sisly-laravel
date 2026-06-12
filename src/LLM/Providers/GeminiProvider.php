<?php

declare(strict_types=1);

namespace Sisly\LLM\Providers;

use Illuminate\Support\Facades\Http;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\LLM\LLMResponse;

/**
 * Google Gemini API provider for LLM completions.
 *
 * Supports Gemini Pro and Gemini Pro Vision models.
 */
class GeminiProvider implements LLMProviderInterface
{
    private const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const DEFAULT_MODEL = 'gemini-pro';
    private const DEFAULT_TIMEOUT = 30;
    private const DEFAULT_MAX_TOKENS = 150;
    private const DEFAULT_TEMPERATURE = 0.7;

    private string $apiKey;
    private string $model;
    private int $timeout;
    private int $maxRetries;
    private int $retryDelay;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? self::DEFAULT_MODEL;
        $this->timeout = $config['timeout'] ?? self::DEFAULT_TIMEOUT;
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->retryDelay = $config['retry_delay'] ?? 1000; // milliseconds
    }

    /**
     * Generate a completion from Gemini.
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
            return LLMResponse::failure('Gemini API key not configured');
        }

        $model = $options['model'] ?? $this->model;
        $contents = $this->formatMessages($messages, $systemPrompt);

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS,
                'temperature' => $options['temperature'] ?? self::DEFAULT_TEMPERATURE,
            ],
        ];

        // Add optional parameters
        if (isset($options['top_p'])) {
            $payload['generationConfig']['topP'] = $options['top_p'];
        }
        if (isset($options['top_k'])) {
            $payload['generationConfig']['topK'] = $options['top_k'];
        }

        // Add safety settings (permissive for coaching context)
        $payload['safetySettings'] = $this->getSafetySettings();

        return $this->executeWithRetry($model, $payload);
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
        return 'gemini';
    }

    /**
     * Format messages for the Gemini API.
     *
     * Gemini uses a different format than OpenAI:
     * - "user" and "model" roles instead of "user" and "assistant"
     * - No separate system message; prepend to first user message
     *
     * @param array<array{role: string, content: string}> $messages
     * @return array<array{role: string, parts: array<array{text: string}>}>
     */
    private function formatMessages(array $messages, string $systemPrompt): array
    {
        $contents = [];
        $isFirstUserMessage = true;

        foreach ($messages as $message) {
            $role = $message['role'] === 'assistant' ? 'model' : 'user';
            $content = $message['content'];

            // Prepend system prompt to first user message
            if ($isFirstUserMessage && $role === 'user' && !empty($systemPrompt)) {
                $content = $systemPrompt . "\n\n---\n\n" . $content;
                $isFirstUserMessage = false;
            } elseif ($role === 'user') {
                $isFirstUserMessage = false;
            }

            $contents[] = [
                'role' => $role,
                'parts' => [
                    ['text' => $content],
                ],
            ];
        }

        return $contents;
    }

    /**
     * Get safety settings for Gemini.
     *
     * Set to block none for emotional coaching context.
     *
     * @return array<array{category: string, threshold: string}>
     */
    private function getSafetySettings(): array
    {
        return [
            [
                'category' => 'HARM_CATEGORY_HARASSMENT',
                'threshold' => 'BLOCK_NONE',
            ],
            [
                'category' => 'HARM_CATEGORY_HATE_SPEECH',
                'threshold' => 'BLOCK_NONE',
            ],
            [
                'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                'threshold' => 'BLOCK_NONE',
            ],
            [
                'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                'threshold' => 'BLOCK_NONE',
            ],
        ];
    }

    /**
     * Execute the API request with retry logic.
     *
     * @param array<string, mixed> $payload
     */
    private function executeWithRetry(string $model, array $payload): LLMResponse
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = $this->makeRequest($model, $payload);

                if ($response->successful()) {
                    return $this->parseResponse($response->json(), $model);
                }

                // Handle rate limiting
                if ($response->status() === 429) {
                    $delay = $this->getBackoffDelay($attempt);
                    usleep($delay * 1000);
                    continue;
                }

                // Handle server errors (5xx) with retry
                if ($response->status() >= 500) {
                    $lastError = "Gemini server error: {$response->status()}";
                    usleep($this->getBackoffDelay($attempt) * 1000);
                    continue;
                }

                // Client errors (4xx) don't retry
                $body = $response->json();
                $errorMessage = $body['error']['message'] ?? "HTTP {$response->status()}";
                return LLMResponse::failure("Gemini error: {$errorMessage}");

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();

                if ($attempt < $this->maxRetries) {
                    usleep($this->getBackoffDelay($attempt) * 1000);
                }
            }
        }

        return LLMResponse::failure("Gemini request failed after {$this->maxRetries} attempts: {$lastError}");
    }

    /**
     * Make the HTTP request to Gemini.
     *
     * @param array<string, mixed> $payload
     */
    private function makeRequest(string $model, array $payload): \Illuminate\Http\Client\Response
    {
        $url = self::API_BASE_URL . "/{$model}:generateContent?key={$this->apiKey}";

        return Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
            ->timeout($this->timeout)
            ->post($url, $payload);
    }

    /**
     * Parse the Gemini response.
     *
     * @param array<string, mixed> $data
     */
    private function parseResponse(array $data, string $model): LLMResponse
    {
        $candidates = $data['candidates'] ?? [];

        if (empty($candidates)) {
            // Check for safety blocking
            if (isset($data['promptFeedback']['blockReason'])) {
                return LLMResponse::failure("Gemini blocked: {$data['promptFeedback']['blockReason']}");
            }
            return LLMResponse::failure('Gemini returned no candidates');
        }

        $candidate = $candidates[0];

        // Check for content filtering
        if (isset($candidate['finishReason']) && $candidate['finishReason'] === 'SAFETY') {
            return LLMResponse::failure('Gemini response blocked by safety filter');
        }

        $content = $candidate['content']['parts'][0]['text'] ?? '';
        $finishReason = $candidate['finishReason'] ?? null;

        // Gemini provides token counts in usageMetadata
        $usage = $data['usageMetadata'] ?? [];
        $promptTokens = $usage['promptTokenCount'] ?? null;
        $completionTokens = $usage['candidatesTokenCount'] ?? null;

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
