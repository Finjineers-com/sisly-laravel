<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit;

use Sisly\DTOs\CoachInfo;
use Sisly\Enums\CoachId;
use Sisly\Facades\Sisly;
use Sisly\SislyManager;
use Sisly\Tests\TestCase;

class FacadeTest extends TestCase
{
    public function test_facade_resolves_to_sisly_manager(): void
    {
        $this->assertInstanceOf(SislyManager::class, Sisly::getFacadeRoot());
    }

    public function test_facade_can_get_coaches(): void
    {
        $coaches = Sisly::getCoaches();

        $this->assertIsArray($coaches);
        $this->assertNotEmpty($coaches);
        $this->assertContainsOnlyInstancesOf(CoachInfo::class, $coaches);
    }

    public function test_facade_can_get_specific_coach(): void
    {
        $coach = Sisly::getCoach(CoachId::MEETLY);

        $this->assertInstanceOf(CoachInfo::class, $coach);
        $this->assertEquals('Meetly', $coach->name);
        $this->assertEquals(CoachId::MEETLY, $coach->id);
    }

    public function test_facade_can_check_session_exists(): void
    {
        $exists = Sisly::sessionExists('non-existent-session-id');

        $this->assertFalse($exists);
    }
}
