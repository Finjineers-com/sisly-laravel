<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit;

use Sisly\Contracts\SessionStoreInterface;
use Sisly\SislyManager;
use Sisly\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_sisly_manager_is_registered(): void
    {
        $this->assertTrue($this->app->bound(SislyManager::class));
    }

    public function test_sisly_manager_is_singleton(): void
    {
        $instance1 = $this->app->make(SislyManager::class);
        $instance2 = $this->app->make(SislyManager::class);

        $this->assertSame($instance1, $instance2);
    }

    public function test_sisly_alias_resolves_to_manager(): void
    {
        $this->assertTrue($this->app->bound('sisly'));
        $this->assertInstanceOf(SislyManager::class, $this->app->make('sisly'));
    }

    public function test_session_store_interface_is_bound(): void
    {
        $this->assertTrue($this->app->bound(SessionStoreInterface::class));
    }

    public function test_config_is_merged(): void
    {
        $config = $this->app['config']->get('sisly');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('llm', $config);
        $this->assertArrayHasKey('fsm', $config);
        $this->assertArrayHasKey('session', $config);
        $this->assertArrayHasKey('coaches', $config);
        $this->assertArrayHasKey('safety', $config);
        $this->assertArrayHasKey('arabic', $config);
    }

    public function test_default_coach_is_meetly(): void
    {
        $defaultCoach = $this->app['config']->get('sisly.coaches.default');

        $this->assertEquals('meetly', $defaultCoach);
    }

    public function test_crisis_detection_is_enabled_by_default(): void
    {
        $crisisDetection = $this->app['config']->get('sisly.safety.crisis_detection');

        $this->assertTrue($crisisDetection);
    }
}
