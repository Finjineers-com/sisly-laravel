<?php

declare(strict_types=1);

namespace Sisly\Contracts;

use Sisly\LLM\LLMResponse;

/**
 * Interface for LLM providers (OpenAI, Gemini, etc.).
 */
interface LLMProviderInterface
{
    /**
     * Generate a completion from the LLM.
     *
     * @param string $prompt The prompt to send
     * @param array<string, mixed> $options Provider-specific options (temperature, max_tokens, etc.)
     */
    public function generate(string $prompt, array $options = []): LLMResponse;

    /**
     * Generate a completion with conversation history.
     *
     * @param array<array{role: string, content: string}> $messages Conversation history
     * @param string $systemPrompt System prompt to prepend
     * @param array<string, mixed> $options Provider-specific options
     */
    public function chat(array $messages, string $systemPrompt = '', array $options = []): LLMResponse;

    /**
     * Check if the provider is available and configured.
     */
    public function isAvailable(): bool;

    /**
     * Get the provider name.
     */
    public function getName(): string;
}
