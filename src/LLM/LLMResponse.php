<?php

declare(strict_types=1);

namespace Sisly\LLM;

/**
 * Response from an LLM provider.
 */
class LLMResponse
{
    public function __construct(
        public readonly string $content,
        public readonly bool $success,
        public readonly ?string $error = null,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly ?string $model = null,
        public readonly ?string $finishReason = null,
    ) {}

    /**
     * Create a successful response.
     */
    public static function success(
        string $content,
        ?int $promptTokens = null,
        ?int $completionTokens = null,
        ?string $model = null,
        ?string $finishReason = null,
    ): self {
        return new self(
            content: $content,
            success: true,
            error: null,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            model: $model,
            finishReason: $finishReason,
        );
    }

    /**
     * Create a failed response.
     */
    public static function failure(string $error): self
    {
        return new self(
            content: '',
            success: false,
            error: $error,
        );
    }

    /**
     * Get total token usage.
     */
    public function getTotalTokens(): ?int
    {
        if ($this->promptTokens === null || $this->completionTokens === null) {
            return null;
        }
        return $this->promptTokens + $this->completionTokens;
    }
}
