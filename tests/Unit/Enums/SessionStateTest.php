<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Sisly\Enums\SessionState;

class SessionStateTest extends TestCase
{
    public function test_all_states_have_correct_values(): void
    {
        $this->assertEquals('intake', SessionState::INTAKE->value);
        $this->assertEquals('risk_triage', SessionState::RISK_TRIAGE->value);
        $this->assertEquals('exploration', SessionState::EXPLORATION->value);
        $this->assertEquals('deepening', SessionState::DEEPENING->value);
        $this->assertEquals('problem_solving', SessionState::PROBLEM_SOLVING->value);
        $this->assertEquals('closing', SessionState::CLOSING->value);
        $this->assertEquals('crisis_intervention', SessionState::CRISIS_INTERVENTION->value);
    }

    public function test_closing_is_terminal(): void
    {
        $this->assertTrue(SessionState::CLOSING->isTerminal());
        $this->assertFalse(SessionState::INTAKE->isTerminal());
        $this->assertFalse(SessionState::EXPLORATION->isTerminal());
    }

    public function test_crisis_intervention_is_crisis(): void
    {
        $this->assertTrue(SessionState::CRISIS_INTERVENTION->isCrisis());
        $this->assertFalse(SessionState::INTAKE->isCrisis());
        $this->assertFalse(SessionState::CLOSING->isCrisis());
    }

    public function test_labels_are_human_readable(): void
    {
        $this->assertEquals('Intake', SessionState::INTAKE->label());
        $this->assertEquals('Crisis Intervention', SessionState::CRISIS_INTERVENTION->label());
        $this->assertEquals('Problem Solving', SessionState::PROBLEM_SOLVING->label());
    }

    public function test_can_create_from_string(): void
    {
        $state = SessionState::from('exploration');

        $this->assertEquals(SessionState::EXPLORATION, $state);
    }
}
