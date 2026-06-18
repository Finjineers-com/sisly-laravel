<?php

declare(strict_types=1);

namespace Sisly\Tests\Integration;

use Sisly\LLM\Providers\OpenAIProvider;
use Sisly\LLM\Providers\GeminiProvider;

/**
 * Integration tests for live LLM API calls.
 *
 * Run with: composer test:integration
 * Or: OPENAI_API_KEY=sk-xxx ./vendor/bin/phpunit --testsuite Integration --filter LiveLLMTest
 */
class LiveLLMTest extends IntegrationTestCase
{
    public function test_openai_provider_can_generate_response(): void
    {
        $this->requireOpenAI();

        $provider = new OpenAIProvider([
            'api_key' => $this->openaiApiKey,
            'model' => 'gpt-4-turbo',
            'timeout' => 30,
        ]);

        $response = $provider->generate('Say "Hello, test!" and nothing else.');

        $this->assertTrue($response->success, 'OpenAI call failed: ' . ($response->error ?? 'unknown error'));
        $this->assertNotEmpty($response->content);
        $this->assertStringContainsStringIgnoringCase('hello', $response->content);
    }

    public function test_openai_provider_can_chat(): void
    {
        $this->requireOpenAI();

        $provider = new OpenAIProvider([
            'api_key' => $this->openaiApiKey,
            'model' => 'gpt-4-turbo',
            'timeout' => 30,
        ]);

        $messages = [
            ['role' => 'user', 'content' => 'I am feeling anxious about my presentation tomorrow.'],
        ];

        $systemPrompt = 'You are an empathetic emotional coach. Respond in 1-2 short sentences.';

        $response = $provider->chat($messages, $systemPrompt, [
            'temperature' => 0.7,
            'max_tokens' => 100,
        ]);

        $this->assertTrue($response->success, 'OpenAI chat failed: ' . ($response->error ?? 'unknown error'));
        $this->assertNotEmpty($response->content);

        // Response should show empathy, not be dismissive
        $content = strtolower($response->content);
        $empathyIndicators = ['understand', 'hear', 'feel', 'anxious', 'normal', 'presentation', 'natural'];
        $hasEmpathy = false;
        foreach ($empathyIndicators as $indicator) {
            if (str_contains($content, $indicator)) {
                $hasEmpathy = true;
                break;
            }
        }
        $this->assertTrue($hasEmpathy, "Response should show empathy. Got: {$response->content}");
    }

    public function test_gemini_provider_can_generate_response(): void
    {
        $this->requireGemini();

        $provider = new GeminiProvider([
            'api_key' => $this->geminiApiKey,
            'model' => 'gemini-pro',
            'timeout' => 30,
        ]);

        $response = $provider->generate('Say "Hello, test!" and nothing else.');

        $this->assertTrue($response->success, 'Gemini call failed: ' . ($response->error ?? 'unknown error'));
        $this->assertNotEmpty($response->content);
        $this->assertStringContainsStringIgnoringCase('hello', $response->content);
    }

    public function test_gemini_provider_can_chat(): void
    {
        $this->requireGemini();

        $provider = new GeminiProvider([
            'api_key' => $this->geminiApiKey,
            'model' => 'gemini-pro',
            'timeout' => 30,
        ]);

        $messages = [
            ['role' => 'user', 'content' => 'I am feeling overwhelmed with work.'],
        ];

        $systemPrompt = 'You are an empathetic emotional coach. Respond in 1-2 short sentences.';

        $response = $provider->chat($messages, $systemPrompt, [
            'temperature' => 0.7,
            'max_tokens' => 100,
        ]);

        $this->assertTrue($response->success, 'Gemini chat failed: ' . ($response->error ?? 'unknown error'));
        $this->assertNotEmpty($response->content);
    }

    public function test_llm_handles_arabic_input(): void
    {
        $this->requireAnyLLM();
        $this->configureRealLLM();

        $driver = $this->getPreferredDriver();

        if ($driver === 'openai') {
            $provider = new OpenAIProvider([
                'api_key' => $this->openaiApiKey,
                'model' => 'gpt-4-turbo',
            ]);
        } else {
            $provider = new GeminiProvider([
                'api_key' => $this->geminiApiKey,
                'model' => 'gemini-pro',
            ]);
        }

        // Arabic input: "I feel anxious"
        $response = $provider->generate('Respond in English to: أشعر بالقلق (I feel anxious). Just say "I understand" in one sentence.');

        $this->assertTrue($response->success, 'LLM failed with Arabic input: ' . ($response->error ?? 'unknown'));
        $this->assertNotEmpty($response->content);
    }

    public function test_llm_respects_temperature_setting(): void
    {
        $this->requireOpenAI();

        $provider = new OpenAIProvider([
            'api_key' => $this->openaiApiKey,
            'model' => 'gpt-4-turbo',
        ]);

        // With temperature 0, responses should be deterministic
        $prompt = 'What is 2+2? Reply with just the number.';

        $response1 = $provider->generate($prompt, ['temperature' => 0.0]);
        $response2 = $provider->generate($prompt, ['temperature' => 0.0]);

        $this->assertTrue($response1->success);
        $this->assertTrue($response2->success);

        // Both should contain "4" with deterministic temperature
        $this->assertStringContainsString('4', $response1->content);
        $this->assertStringContainsString('4', $response2->content);
    }
}
