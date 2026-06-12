<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Coaches;

use PHPUnit\Framework\TestCase;
use Sisly\Coaches\CoachRegistry;
use Sisly\Coaches\MeetlyCoach;
use Sisly\Contracts\CoachInterface;
use Sisly\Enums\CoachId;
use Sisly\Exceptions\SislyException;
use Sisly\LLM\MockProvider;

class CoachRegistryTest extends TestCase
{
    private CoachRegistry $registry;
    private MockProvider $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockProvider = new MockProvider();
        $this->registry = new CoachRegistry($this->mockProvider);
    }

    public function test_get_returns_meetly_coach(): void
    {
        $coach = $this->registry->get(CoachId::MEETLY);

        $this->assertInstanceOf(CoachInterface::class, $coach);
        $this->assertInstanceOf(MeetlyCoach::class, $coach);
        $this->assertEquals(CoachId::MEETLY, $coach->getId());
    }

    public function test_get_returns_same_instance_on_multiple_calls(): void
    {
        $coach1 = $this->registry->get(CoachId::MEETLY);
        $coach2 = $this->registry->get(CoachId::MEETLY);

        $this->assertSame($coach1, $coach2);
    }

    public function test_is_enabled_returns_true_for_all_coaches_by_default(): void
    {
        $this->assertTrue($this->registry->isEnabled(CoachId::MEETLY));
        $this->assertTrue($this->registry->isEnabled(CoachId::VENTO));
        $this->assertTrue($this->registry->isEnabled(CoachId::LOOPY));
        $this->assertTrue($this->registry->isEnabled(CoachId::PRESSO));
        $this->assertTrue($this->registry->isEnabled(CoachId::BOOSTLY));
        $this->assertTrue($this->registry->isEnabled(CoachId::SAFEO));
    }

    public function test_registry_with_limited_enabled_coaches(): void
    {
        $registry = new CoachRegistry(
            llm: $this->mockProvider,
            enabledCoaches: ['meetly', 'vento'],
        );

        $this->assertTrue($registry->isEnabled(CoachId::MEETLY));
        $this->assertTrue($registry->isEnabled(CoachId::VENTO));
        $this->assertFalse($registry->isEnabled(CoachId::LOOPY));
        $this->assertFalse($registry->isEnabled(CoachId::PRESSO));
        $this->assertFalse($registry->isEnabled(CoachId::BOOSTLY));
        $this->assertFalse($registry->isEnabled(CoachId::SAFEO));
    }

    public function test_get_throws_exception_for_disabled_coach(): void
    {
        $registry = new CoachRegistry(
            llm: $this->mockProvider,
            enabledCoaches: ['meetly'],
        );

        $this->expectException(SislyException::class);
        $this->expectExceptionMessage('not enabled');

        $registry->get(CoachId::VENTO);
    }

    public function test_get_all_enabled_returns_all_coaches(): void
    {
        $coaches = $this->registry->getAllEnabled();

        $this->assertCount(6, $coaches);

        foreach ($coaches as $coach) {
            $this->assertInstanceOf(CoachInterface::class, $coach);
        }
    }

    public function test_get_all_enabled_returns_only_enabled_coaches(): void
    {
        $registry = new CoachRegistry(
            llm: $this->mockProvider,
            enabledCoaches: ['meetly', 'vento'],
        );

        $coaches = $registry->getAllEnabled();

        $this->assertCount(2, $coaches);
    }

    public function test_get_enabled_ids_returns_array_of_ids(): void
    {
        $ids = $this->registry->getEnabledIds();

        $this->assertIsArray($ids);
        $this->assertContains('meetly', $ids);
        $this->assertContains('vento', $ids);
        $this->assertContains('loopy', $ids);
        $this->assertContains('presso', $ids);
        $this->assertContains('boostly', $ids);
        $this->assertContains('safeo', $ids);
    }

    public function test_register_allows_custom_coach(): void
    {
        // Create a mock custom coach
        $customCoach = $this->createMock(CoachInterface::class);
        $customCoach->method('getId')->willReturn(CoachId::MEETLY);

        $this->registry->register($customCoach);

        $coach = $this->registry->get(CoachId::MEETLY);

        $this->assertSame($customCoach, $coach);
    }

    public function test_creates_all_coach_types(): void
    {
        // Test that all coach types can be created (using temporary fallbacks)
        foreach (CoachId::cases() as $coachId) {
            $coach = $this->registry->get($coachId);
            $this->assertInstanceOf(CoachInterface::class, $coach);
        }
    }
}
