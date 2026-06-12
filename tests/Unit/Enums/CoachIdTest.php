<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Sisly\Enums\CoachId;

class CoachIdTest extends TestCase
{
    public function test_all_coaches_have_correct_values(): void
    {
        $this->assertEquals('meetly', CoachId::MEETLY->value);
        $this->assertEquals('vento', CoachId::VENTO->value);
        $this->assertEquals('loopy', CoachId::LOOPY->value);
        $this->assertEquals('presso', CoachId::PRESSO->value);
        $this->assertEquals('boostly', CoachId::BOOSTLY->value);
        $this->assertEquals('safeo', CoachId::SAFEO->value);
    }

    public function test_display_names_are_capitalized(): void
    {
        $this->assertEquals('Meetly', CoachId::MEETLY->displayName());
        $this->assertEquals('Vento', CoachId::VENTO->displayName());
        $this->assertEquals('Loopy', CoachId::LOOPY->displayName());
        $this->assertEquals('Presso', CoachId::PRESSO->displayName());
        $this->assertEquals('Boostly', CoachId::BOOSTLY->displayName());
        $this->assertEquals('Safeo', CoachId::SAFEO->displayName());
    }

    public function test_each_coach_has_focus_description(): void
    {
        foreach (CoachId::cases() as $coach) {
            $focus = $coach->focus();
            $this->assertNotEmpty($focus);
            $this->assertIsString($focus);
        }
    }

    public function test_values_returns_all_coach_ids(): void
    {
        $values = CoachId::values();

        $this->assertCount(6, $values);
        $this->assertContains('meetly', $values);
        $this->assertContains('vento', $values);
        $this->assertContains('loopy', $values);
        $this->assertContains('presso', $values);
        $this->assertContains('boostly', $values);
        $this->assertContains('safeo', $values);
    }

    public function test_can_create_from_string(): void
    {
        $coach = CoachId::from('meetly');

        $this->assertEquals(CoachId::MEETLY, $coach);
    }
}
