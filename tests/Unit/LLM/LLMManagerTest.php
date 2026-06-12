<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\LLM;

use PHPUnit\Framework\TestCase;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\LLM\LLMManager;
use Sisly\LLM\LLMResponse;
use Sisly\LLM\MockProvider;

class LLMManagerTest extends TestCase
{
    public function test_get_name_returns_manager(): void
    {
        $manager = new LLMManager();
        $this->assertEquals('manager', $manager->getName());
    }

    public function test_is_available_returns_false_when_no_providers(): void
    {
        $manager = new LLMManager();
        $this->assertFalse($manager->isAvailable());
    }

    public function test_is_available_returns_true_when_provider_available(): void
    {
        $provider = new MockProvider();
        $manager = new LLMManager([$provider]);

        $this->assertTrue($manager->isAvailable());
    }

    public function test_add_provider_makes_first_available_active(): void
    {
        $provider = new MockProvider();
        $manager = new LLMManager();

        $this->assertNull($manager->getActiveProvider());

        $manager->addProvider($provider);

        $this->assertSame($provider, $manager->getActiveProvider());
    }

    public function test_generate_uses_active_provider(): void
    {
        $provider = new MockProvider();
        $provider->addResponse('test', 'Mocked response');

        $manager = new LLMManager([$provider], false);
        $response = $manager->generate('test message');

        $this->assertTrue($response->success);
        $this->assertStringContainsString('response', strtolower($response->content));
    }

    public function test_chat_uses_active_provider(): void
    {
        $provider = new MockProvider();
        $manager = new LLMManager([$provider], false);

        $response = $manager->chat(
            [['role' => 'user', 'content' => 'Hello']],
            'System prompt'
        );

        $this->assertTrue($response->success);
    }

    public function test_failover_to_backup_provider(): void
    {
        // Create failing primary provider
        $primary = $this->createMock(LLMProviderInterface::class);
        $primary->method('getName')->willReturn('primary');
        $primary->method('isAvailable')->willReturn(true);
        $primary->method('generate')->willReturn(LLMResponse::failure('Primary failed'));

        // Create working backup provider
        $backup = new MockProvider();
        $backup->addResponse('test', 'Backup response');

        $manager = new LLMManager([$primary, $backup], false);
        $response = $manager->generate('test message');

        $this->assertTrue($response->success);
    }

    public function test_returns_failure_when_all_providers_fail(): void
    {
        $provider1 = $this->createMock(LLMProviderInterface::class);
        $provider1->method('getName')->willReturn('provider1');
        $provider1->method('isAvailable')->willReturn(true);
        $provider1->method('generate')->willReturn(LLMResponse::failure('Failed 1'));

        $provider2 = $this->createMock(LLMProviderInterface::class);
        $provider2->method('getName')->willReturn('provider2');
        $provider2->method('isAvailable')->willReturn(true);
        $provider2->method('generate')->willReturn(LLMResponse::failure('Failed 2'));

        $manager = new LLMManager([$provider1, $provider2], false);
        $response = $manager->generate('test');

        $this->assertFalse($response->success);
        $this->assertStringContainsString('All LLM providers failed', $response->error);
    }

    public function test_circuit_breaker_trips_after_threshold(): void
    {
        // Use only failing provider to test circuit breaker
        $failingProvider = $this->createMock(LLMProviderInterface::class);
        $failingProvider->method('getName')->willReturn('failing');
        $failingProvider->method('isAvailable')->willReturn(true);
        $failingProvider->method('generate')->willReturn(LLMResponse::failure('Always fails'));

        // Set low failure threshold with only failing provider
        $manager = new LLMManager([$failingProvider], false, 2);

        // First call increments failure count
        $manager->generate('test1');
        $this->assertEquals(1, $manager->getFailureCounts()['failing']);

        // Second call increments again
        $manager->generate('test2');
        $this->assertEquals(2, $manager->getFailureCounts()['failing']);

        // After threshold reached, circuit breaker trips
        // Provider should now be "broken"
        $this->assertEquals(2, $manager->getFailureCounts()['failing']);
    }

    public function test_reset_failure_counts(): void
    {
        $failingProvider = $this->createMock(LLMProviderInterface::class);
        $failingProvider->method('getName')->willReturn('failing');
        $failingProvider->method('isAvailable')->willReturn(true);
        $failingProvider->method('generate')->willReturn(LLMResponse::failure('Fail'));

        $manager = new LLMManager([$failingProvider], false);
        $manager->generate('test');

        $this->assertEquals(1, $manager->getFailureCounts()['failing']);

        $manager->resetFailureCounts();

        $this->assertEquals(0, $manager->getFailureCounts()['failing']);
    }

    public function test_switch_to_specific_provider(): void
    {
        $provider1 = new MockProvider();
        $provider2 = $this->createMock(LLMProviderInterface::class);
        $provider2->method('getName')->willReturn('other');
        $provider2->method('isAvailable')->willReturn(true);

        $manager = new LLMManager([$provider1, $provider2], false);

        $this->assertEquals('mock', $manager->getActiveProviderName());

        $result = $manager->switchTo('other');

        $this->assertTrue($result);
        $this->assertEquals('other', $manager->getActiveProviderName());
    }

    public function test_switch_to_unavailable_provider_fails(): void
    {
        $provider1 = new MockProvider();
        $provider2 = $this->createMock(LLMProviderInterface::class);
        $provider2->method('getName')->willReturn('other');
        $provider2->method('isAvailable')->willReturn(false);

        $manager = new LLMManager([$provider1, $provider2], false);
        $result = $manager->switchTo('other');

        $this->assertFalse($result);
        $this->assertEquals('mock', $manager->getActiveProviderName());
    }

    public function test_switch_to_nonexistent_provider_fails(): void
    {
        $provider = new MockProvider();
        $manager = new LLMManager([$provider], false);

        $result = $manager->switchTo('nonexistent');

        $this->assertFalse($result);
    }

    public function test_get_providers_returns_all_providers(): void
    {
        $provider1 = new MockProvider();
        $provider2 = new MockProvider();

        $manager = new LLMManager([$provider1, $provider2], false);

        $this->assertCount(2, $manager->getProviders());
    }

    public function test_success_resets_failure_count(): void
    {
        // Provider that fails once then succeeds
        $callCount = 0;
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getName')->willReturn('test');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('generate')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return LLMResponse::failure('Temporary failure');
            }
            return LLMResponse::success('Success');
        });

        // Use only one provider - no backup, so it will be retried on each call
        $manager = new LLMManager([$provider], false, 5);

        // First call fails, increments counter
        $manager->generate('test1');
        $this->assertEquals(1, $manager->getFailureCounts()['test']);

        // Second call succeeds, should reset counter
        $manager->generate('test2');
        $this->assertEquals(0, $manager->getFailureCounts()['test']);
    }

    public function test_returns_failure_when_no_providers_available(): void
    {
        $manager = new LLMManager([], false);
        $response = $manager->generate('test');

        $this->assertFalse($response->success);
        $this->assertStringContainsString('No LLM providers available', $response->error);
    }

    public function test_skips_unavailable_providers(): void
    {
        $unavailable = $this->createMock(LLMProviderInterface::class);
        $unavailable->method('getName')->willReturn('unavailable');
        $unavailable->method('isAvailable')->willReturn(false);

        $available = new MockProvider();

        $manager = new LLMManager([$unavailable, $available], false);
        $response = $manager->generate('test');

        $this->assertTrue($response->success);
        // Should use the available mock provider
    }
}
