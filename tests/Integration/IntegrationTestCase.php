<?php

declare(strict_types=1);

namespace Sisly\Tests\Integration;

use Sisly\Tests\TestCase;

/**
 * Base test case for integration tests that require real API calls.
 *
 * These tests are skipped by default unless API keys are configured.
 * To run integration tests:
 *
 *   1. Copy .env.testing.example to .env.testing
 *   2. Add your API keys to .env.testing
 *   3. Run: composer test:integration
 *
 * Or run with environment variables:
 *   OPENAI_API_KEY=sk-xxx ./vendor/bin/phpunit --testsuite Integration
 */
abstract class IntegrationTestCase extends TestCase
{
    protected ?string $openaiApiKey = null;
    protected ?string $geminiApiKey = null;

    protected function setUp(): void
    {
        // Load .env.testing file if it exists (before parent::setUp)
        $this->loadEnvTestingFile();

        parent::setUp();

        // Load API keys from environment
        $this->openaiApiKey = $this->getEnvKey('OPENAI_API_KEY');
        $this->geminiApiKey = $this->getEnvKey('GEMINI_API_KEY');
    }

    /**
     * Load the .env.testing file if it exists.
     */
    protected function loadEnvTestingFile(): void
    {
        $envFile = dirname(__DIR__, 2) . '/.env.testing';

        if (!file_exists($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            // Parse KEY=value
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Only set if not already set in environment
                if (empty(getenv($key))) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    /**
     * Get an API key from environment, checking multiple sources.
     */
    protected function getEnvKey(string $key): ?string
    {
        // Check $_ENV first (from phpunit.xml or .env.testing)
        if (!empty($_ENV[$key])) {
            return $_ENV[$key];
        }

        // Check getenv() for system environment variables
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }

        // Check Laravel config
        $configKey = match ($key) {
            'OPENAI_API_KEY' => 'sisly.llm.openai.api_key',
            'GEMINI_API_KEY' => 'sisly.llm.gemini.api_key',
            default => null,
        };

        if ($configKey) {
            $value = config($configKey);
            if (!empty($value) && $value !== 'test-api-key') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Skip the test if OpenAI API key is not configured.
     */
    protected function requireOpenAI(): void
    {
        if (empty($this->openaiApiKey)) {
            $this->markTestSkipped(
                'OpenAI API key not configured. Set OPENAI_API_KEY in .env.testing or environment.'
            );
        }
    }

    /**
     * Skip the test if Gemini API key is not configured.
     */
    protected function requireGemini(): void
    {
        if (empty($this->geminiApiKey)) {
            $this->markTestSkipped(
                'Gemini API key not configured. Set GEMINI_API_KEY in .env.testing or environment.'
            );
        }
    }

    /**
     * Skip the test if no LLM API key is configured.
     */
    protected function requireAnyLLM(): void
    {
        if (empty($this->openaiApiKey) && empty($this->geminiApiKey)) {
            $this->markTestSkipped(
                'No LLM API key configured. Set OPENAI_API_KEY or GEMINI_API_KEY in .env.testing or environment.'
            );
        }
    }

    /**
     * Get the preferred LLM driver based on available keys.
     */
    protected function getPreferredDriver(): string
    {
        if (!empty($this->openaiApiKey)) {
            return 'openai';
        }

        if (!empty($this->geminiApiKey)) {
            return 'gemini';
        }

        return 'openai'; // Default
    }

    /**
     * Configure the app with real API keys for integration testing.
     */
    protected function configureRealLLM(): void
    {
        $driver = $this->getPreferredDriver();

        config(['sisly.llm.driver' => $driver]);

        if (!empty($this->openaiApiKey)) {
            config(['sisly.llm.openai.api_key' => $this->openaiApiKey]);
        }

        if (!empty($this->geminiApiKey)) {
            config(['sisly.llm.gemini.api_key' => $this->geminiApiKey]);
        }
    }

    /**
     * Define environment setup for integration tests.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Use array cache for sessions during testing
        $app['config']->set('cache.default', 'array');
        $app['config']->set('sisly.session.driver', 'cache');

        // Reduce timeouts for faster test feedback
        $app['config']->set('sisly.llm.openai.timeout', 60);
        $app['config']->set('sisly.llm.gemini.timeout', 60);
    }
}
