<?php

declare(strict_types=1);

namespace Sisly\LLM;

use Sisly\Contracts\LLMProviderInterface;
use Sisly\Enums\CoachId;

/**
 * Mock LLM provider for testing.
 *
 * Returns predictable responses based on message content.
 */
class MockProvider implements LLMProviderInterface
{
    /**
     * @var array<string, string> Preset responses for specific prompts/messages
     */
    private array $responses = [];

    /**
     * @var array<array{prompt: string, options: array<string, mixed>}> Call history
     */
    private array $callHistory = [];

    /**
     * @var bool Whether the provider should simulate being unavailable
     */
    private bool $simulateUnavailable = false;

    /**
     * @var string|null Error to simulate
     */
    private ?string $simulateError = null;

    /**
     * Generate a completion from the mock LLM.
     */
    public function generate(string $prompt, array $options = []): LLMResponse
    {
        $this->callHistory[] = ['prompt' => $prompt, 'options' => $options];

        if ($this->simulateError !== null) {
            return LLMResponse::failure($this->simulateError);
        }

        // Check for preset response
        foreach ($this->responses as $pattern => $response) {
            if (str_contains($prompt, $pattern)) {
                return LLMResponse::success($response, 10, 20, 'mock-model');
            }
        }

        // Default: generate dispatcher-style response for coach classification
        if (str_contains($prompt, 'classify') || str_contains($prompt, 'dispatcher')) {
            return $this->generateDispatcherResponse($prompt);
        }

        // Default coaching response
        return $this->generateCoachingResponse($prompt);
    }

    /**
     * Generate a completion with conversation history.
     */
    public function chat(array $messages, string $systemPrompt = '', array $options = []): LLMResponse
    {
        $this->callHistory[] = [
            'messages' => $messages,
            'systemPrompt' => $systemPrompt,
            'options' => $options,
        ];

        if ($this->simulateError !== null) {
            return LLMResponse::failure($this->simulateError);
        }

        // Get last user message for response generation
        $lastUserMessage = '';
        foreach (array_reverse($messages) as $message) {
            if ($message['role'] === 'user') {
                $lastUserMessage = $message['content'];
                break;
            }
        }

        // Check for preset response
        foreach ($this->responses as $pattern => $response) {
            if (str_contains($lastUserMessage, $pattern) || str_contains($systemPrompt, $pattern)) {
                return LLMResponse::success($response, 10, 20, 'mock-model');
            }
        }

        return $this->generateCoachingResponse($lastUserMessage);
    }

    /**
     * Check if the provider is available.
     */
    public function isAvailable(): bool
    {
        return !$this->simulateUnavailable;
    }

    /**
     * Get the provider name.
     */
    public function getName(): string
    {
        return 'mock';
    }

    /**
     * Add a preset response for a specific pattern.
     */
    public function addResponse(string $pattern, string $response): self
    {
        $this->responses[$pattern] = $response;
        return $this;
    }

    /**
     * Clear all preset responses.
     */
    public function clearResponses(): self
    {
        $this->responses = [];
        return $this;
    }

    /**
     * Simulate provider being unavailable.
     */
    public function simulateUnavailable(bool $unavailable = true): self
    {
        $this->simulateUnavailable = $unavailable;
        return $this;
    }

    /**
     * Simulate an error response.
     */
    public function simulateError(?string $error): self
    {
        $this->simulateError = $error;
        return $this;
    }

    /**
     * Get call history for assertions.
     *
     * @return array<array{prompt?: string, messages?: array, options: array<string, mixed>}>
     */
    public function getCallHistory(): array
    {
        return $this->callHistory;
    }

    /**
     * Clear call history.
     */
    public function clearHistory(): self
    {
        $this->callHistory = [];
        return $this;
    }

    /**
     * Get the number of calls made.
     */
    public function getCallCount(): int
    {
        return count($this->callHistory);
    }

    /**
     * Generate a mock dispatcher response based on message content.
     */
    private function generateDispatcherResponse(string $prompt): LLMResponse
    {
        $coach = CoachId::MEETLY; // Default coach
        $confidence = 0.85;
        $reasoning = 'Detected general emotional support need';

        // Extract user message from prompt if it contains the dispatcher format
        $userMessage = $prompt;
        if (preg_match('/User message:\s*(.+?)(?:\n|Enabled coaches:)/is', $prompt, $matches)) {
            $userMessage = trim($matches[1]);
        }

        // Analyze prompt for keywords to route to appropriate coach
        $promptLower = strtolower($userMessage);

        if (str_contains($promptLower, 'meeting') || str_contains($promptLower, 'presentation')) {
            $coach = CoachId::MEETLY;
            $reasoning = 'Detected meeting or presentation anxiety';
        } elseif (str_contains($promptLower, 'angry') || str_contains($promptLower, 'furious') || str_contains($promptLower, 'frustrated')) {
            $coach = CoachId::VENTO;
            $reasoning = 'Detected anger or frustration that needs venting';
        } elseif (str_contains($promptLower, 'thinking') || str_contains($promptLower, 'stuck') || str_contains($promptLower, 'loop')) {
            $coach = CoachId::LOOPY;
            $reasoning = 'Detected rumination or thought loops';
        } elseif (str_contains($promptLower, 'overwhelm') || str_contains($promptLower, 'too much') || str_contains($promptLower, 'pressure')) {
            $coach = CoachId::PRESSO;
            $reasoning = 'Detected overwhelm or pressure';
        } elseif (str_contains($promptLower, 'doubt') || str_contains($promptLower, 'imposter') || str_contains($promptLower, 'confidence')) {
            $coach = CoachId::BOOSTLY;
            $reasoning = 'Detected self-doubt or imposter syndrome';
        }

        $response = json_encode([
            'coach' => $coach->value,
            'confidence' => $confidence,
            'reasoning' => $reasoning,
        ]);

        return LLMResponse::success($response, 15, 25, 'mock-model');
    }

    /**
     * Generate a mock coaching response.
     */
    private function generateCoachingResponse(string $message): LLMResponse
    {
        $messageLower = strtolower($message);

        // Generate contextual coaching responses
        if (str_contains($messageLower, 'anxious') || str_contains($messageLower, 'nervous')) {
            $response = "I hear that you're feeling anxious. That's a completely valid feeling. Can you tell me a bit more about what's triggering this anxiety right now?";
        } elseif (str_contains($messageLower, 'sad') || str_contains($messageLower, 'down')) {
            $response = "It sounds like you're going through a difficult time. I'm here to listen. What's been weighing on you?";
        } elseif (str_contains($messageLower, 'stressed') || str_contains($messageLower, 'overwhelmed')) {
            $response = "Feeling overwhelmed can be really challenging. Let's take a moment together. What feels like the biggest pressure right now?";
        } elseif (str_contains($messageLower, 'help') || str_contains($messageLower, 'technique')) {
            $response = "I'd like to offer you a quick technique. Do you have 30 seconds, 1 minute, or 2 minutes?";
        } else {
            $response = "Thank you for sharing that with me. I'm here with you. Can you tell me more about what you're experiencing?";
        }

        return LLMResponse::success($response, 20, 30, 'mock-model');
    }
}
