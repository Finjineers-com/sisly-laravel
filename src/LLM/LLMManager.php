<?php

declare(strict_types=1);

namespace Sisly\LLM;

use Sisly\Contracts\LLMProviderInterface;
use Sisly\Events\LLMFailoverOccurred;

/**
 * LLM Manager with failover support.
 *
 * Manages multiple LLM providers and automatically fails over
 * to backup providers when the primary fails.
 */
class LLMManager implements LLMProviderInterface
{
    /**
     * @var array<LLMProviderInterface>
     */
    private array $providers = [];

    /**
     * @var LLMProviderInterface|null The currently active provider
     */
    private ?LLMProviderInterface $activeProvider = null;

    /**
     * @var bool Whether to dispatch events on failover
     */
    private bool $dispatchEvents;

    /**
     * @var array<string, int> Track failure counts per provider
     */
    private array $failureCounts = [];

    /**
     * @var int Threshold of failures before removing provider from rotation
     */
    private int $failureThreshold;

    /**
     * @param array<LLMProviderInterface> $providers Ordered list of providers (primary first)
     * @param bool $dispatchEvents Whether to dispatch failover events
     * @param int $failureThreshold Number of failures before circuit breaker
     */
    public function __construct(
        array $providers = [],
        bool $dispatchEvents = true,
        int $failureThreshold = 5,
    ) {
        $this->dispatchEvents = $dispatchEvents;
        $this->failureThreshold = $failureThreshold;

        foreach ($providers as $provider) {
            $this->addProvider($provider);
        }
    }

    /**
     * Add a provider to the manager.
     */
    public function addProvider(LLMProviderInterface $provider): void
    {
        $this->providers[] = $provider;
        $this->failureCounts[$provider->getName()] = 0;

        // Set as active if it's the first available provider
        if ($this->activeProvider === null && $provider->isAvailable()) {
            $this->activeProvider = $provider;
        }
    }

    /**
     * Generate a completion using the active provider with failover.
     */
    public function generate(string $prompt, array $options = []): LLMResponse
    {
        return $this->executeWithFailover(
            fn (LLMProviderInterface $provider) => $provider->generate($prompt, $options)
        );
    }

    /**
     * Generate a completion with conversation history using failover.
     */
    public function chat(array $messages, string $systemPrompt = '', array $options = []): LLMResponse
    {
        return $this->executeWithFailover(
            fn (LLMProviderInterface $provider) => $provider->chat($messages, $systemPrompt, $options)
        );
    }

    /**
     * Check if any provider is available.
     */
    public function isAvailable(): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->isAvailable() && !$this->isCircuitBroken($provider)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the manager name.
     */
    public function getName(): string
    {
        return 'manager';
    }

    /**
     * Get the active provider.
     */
    public function getActiveProvider(): ?LLMProviderInterface
    {
        return $this->activeProvider;
    }

    /**
     * Get the active provider name.
     */
    public function getActiveProviderName(): ?string
    {
        return $this->activeProvider?->getName();
    }

    /**
     * Get all registered providers.
     *
     * @return array<LLMProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Reset failure counts (useful after a period of time).
     */
    public function resetFailureCounts(): void
    {
        foreach ($this->failureCounts as $name => $count) {
            $this->failureCounts[$name] = 0;
        }
    }

    /**
     * Execute an operation with automatic failover.
     *
     * @param callable(LLMProviderInterface): LLMResponse $operation
     */
    private function executeWithFailover(callable $operation): LLMResponse
    {
        $availableProviders = $this->getAvailableProviders();

        if (empty($availableProviders)) {
            return LLMResponse::failure('No LLM providers available');
        }

        $previousProvider = null;

        foreach ($availableProviders as $provider) {
            $response = $operation($provider);

            if ($response->success) {
                // Reset failure count on success
                $this->failureCounts[$provider->getName()] = 0;

                // Update active provider
                if ($this->activeProvider !== $provider) {
                    $this->activeProvider = $provider;
                }

                return $response;
            }

            // Track failure
            $this->failureCounts[$provider->getName()]++;

            // Dispatch failover event if we're switching providers
            if ($previousProvider !== null && $this->dispatchEvents) {
                $this->dispatchFailoverEvent(
                    previousProvider: $previousProvider,
                    newProvider: $provider,
                    error: $response->error ?? 'Unknown error',
                );
            }

            $previousProvider = $provider;
        }

        // All providers failed
        return LLMResponse::failure('All LLM providers failed');
    }

    /**
     * Get available providers (not circuit broken).
     *
     * @return array<LLMProviderInterface>
     */
    private function getAvailableProviders(): array
    {
        $available = [];

        // Put active provider first
        if ($this->activeProvider !== null &&
            $this->activeProvider->isAvailable() &&
            !$this->isCircuitBroken($this->activeProvider)) {
            $available[] = $this->activeProvider;
        }

        // Add other available providers
        foreach ($this->providers as $provider) {
            if ($provider === $this->activeProvider) {
                continue;
            }
            if ($provider->isAvailable() && !$this->isCircuitBroken($provider)) {
                $available[] = $provider;
            }
        }

        return $available;
    }

    /**
     * Check if a provider's circuit breaker is tripped.
     */
    private function isCircuitBroken(LLMProviderInterface $provider): bool
    {
        $failures = $this->failureCounts[$provider->getName()] ?? 0;
        return $failures >= $this->failureThreshold;
    }

    /**
     * Dispatch the failover event.
     */
    private function dispatchFailoverEvent(
        LLMProviderInterface $previousProvider,
        LLMProviderInterface $newProvider,
        string $error,
    ): void {
        $event = new LLMFailoverOccurred(
            previousProvider: $previousProvider->getName(),
            newProvider: $newProvider->getName(),
            error: $error,
            timestamp: new \DateTimeImmutable(),
        );

        event($event);
    }

    /**
     * Force switch to a specific provider by name.
     */
    public function switchTo(string $providerName): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->getName() === $providerName && $provider->isAvailable()) {
                $this->activeProvider = $provider;
                return true;
            }
        }
        return false;
    }

    /**
     * Get failure counts for debugging.
     *
     * @return array<string, int>
     */
    public function getFailureCounts(): array
    {
        return $this->failureCounts;
    }
}
