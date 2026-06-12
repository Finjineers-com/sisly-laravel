<?php

declare(strict_types=1);

namespace Sisly\Exceptions;

/**
 * Exception thrown when LLM operations fail.
 */
class LLMException extends SislyException
{
    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';
    public const REQUEST_FAILED = 'request_failed';
    public const RATE_LIMITED = 'rate_limited';
    public const INVALID_RESPONSE = 'invalid_response';
    public const ALL_PROVIDERS_FAILED = 'all_providers_failed';

    private string $reason;
    private ?string $provider;

    public function __construct(
        string $reason,
        ?string $provider = null,
        string $message = ''
    ) {
        $this->reason = $reason;
        $this->provider = $provider;

        if (empty($message)) {
            $message = match ($reason) {
                self::PROVIDER_UNAVAILABLE => $provider
                    ? "LLM provider '{$provider}' is not available"
                    : 'No LLM provider available',
                self::REQUEST_FAILED => $provider
                    ? "Request to LLM provider '{$provider}' failed"
                    : 'LLM request failed',
                self::RATE_LIMITED => $provider
                    ? "LLM provider '{$provider}' rate limited"
                    : 'LLM rate limited',
                self::INVALID_RESPONSE => 'Invalid response from LLM',
                self::ALL_PROVIDERS_FAILED => 'All LLM providers failed',
                default => 'LLM operation failed',
            };
        }

        parent::__construct($message);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public static function providerUnavailable(?string $provider = null): self
    {
        return new self(self::PROVIDER_UNAVAILABLE, $provider);
    }

    public static function requestFailed(string $provider, string $error): self
    {
        return new self(
            self::REQUEST_FAILED,
            $provider,
            "LLM request to '{$provider}' failed: {$error}"
        );
    }

    public static function rateLimited(string $provider): self
    {
        return new self(self::RATE_LIMITED, $provider);
    }

    public static function allProvidersFailed(): self
    {
        return new self(self::ALL_PROVIDERS_FAILED);
    }
}
