<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\FSM;

use PHPUnit\Framework\TestCase;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\FSM\StateMachine;

class StateMachineTest extends TestCase
{
    private StateMachine $fsm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fsm = new StateMachine();
    }

    // ==================== VALID TRANSITIONS ====================

    public function test_intake_can_transition_to_risk_triage(): void
    {
        $this->assertTrue(
            $this->fsm->canTransition(SessionState::INTAKE, SessionState::RISK_TRIAGE)
        );
    }

    public function test_risk_triage_can_transition_to_exploration(): void
    {
        $this->assertTrue(
            $this->fsm->canTransition(SessionState::RISK_TRIAGE, SessionState::EXPLORATION)
        );
    }

    public function test_risk_triage_can_transition_to_crisis(): void
    {
        $this->assertTrue(
            $this->fsm->canTransition(SessionState::RISK_TRIAGE, SessionState::CRISIS_INTERVENTION)
        );
    }

    public function test_exploration_can_transition_to_deepening(): void
    {
        $this->assertTrue(
            $this->fsm->canTransition(SessionState::EXPLORATION, SessionState::DEEPENING)
        );
    }

    public function test_deepening_can_transition_to_problem_solving(): void
    {
        $this->assertTrue(
            $this->fsm->canTransition(SessionState::DEEPENING, SessionState::PROBLEM_SOLVING)
        );
    }

    public function test_problem_solving_can_transition_to_closing(): void
    {
        $this->assertTrue(
            $this->fsm->canTransition(SessionState::PROBLEM_SOLVING, SessionState::CLOSING)
        );
    }

    // ==================== INVALID TRANSITIONS ====================

    public function test_intake_cannot_skip_to_exploration(): void
    {
        $this->assertFalse(
            $this->fsm->canTransition(SessionState::INTAKE, SessionState::EXPLORATION)
        );
    }

    public function test_exploration_cannot_go_back_to_intake(): void
    {
        $this->assertFalse(
            $this->fsm->canTransition(SessionState::EXPLORATION, SessionState::INTAKE)
        );
    }

    public function test_closing_has_no_transitions(): void
    {
        $transitions = $this->fsm->getValidTransitions(SessionState::CLOSING);
        $this->assertEmpty($transitions);
    }

    public function test_crisis_has_no_transitions(): void
    {
        $transitions = $this->fsm->getValidTransitions(SessionState::CRISIS_INTERVENTION);
        $this->assertEmpty($transitions);
    }

    // ==================== CRISIS TRAP STATE ====================

    public function test_crisis_state_is_trap(): void
    {
        $this->assertTrue($this->fsm->isCrisis(SessionState::CRISIS_INTERVENTION));
    }

    public function test_crisis_never_advances(): void
    {
        $session = $this->createSession();
        $session->transitionTo(SessionState::CRISIS_INTERVENTION);

        // Even with many turns, should never advance
        for ($i = 0; $i < 100; $i++) {
            $this->fsm->incrementStateTurns($session);
        }

        $this->assertFalse($this->fsm->shouldAdvance($session));
    }

    public function test_crisis_get_next_state_returns_null(): void
    {
        $this->assertNull($this->fsm->getNextState(SessionState::CRISIS_INTERVENTION));
    }

    // ==================== TERMINAL STATE ====================

    public function test_closing_is_terminal(): void
    {
        $this->assertTrue($this->fsm->isTerminal(SessionState::CLOSING));
    }

    public function test_other_states_are_not_terminal(): void
    {
        $this->assertFalse($this->fsm->isTerminal(SessionState::INTAKE));
        $this->assertFalse($this->fsm->isTerminal(SessionState::EXPLORATION));
        $this->assertFalse($this->fsm->isTerminal(SessionState::PROBLEM_SOLVING));
    }

    public function test_terminal_state_never_advances(): void
    {
        $session = $this->createSession();
        $session->transitionTo(SessionState::CLOSING);

        $this->fsm->incrementStateTurns($session);
        $this->fsm->incrementStateTurns($session);

        $this->assertFalse($this->fsm->shouldAdvance($session));
    }

    // ==================== TURN LIMITS ====================

    public function test_intake_has_one_turn_limit(): void
    {
        $this->assertEquals(1, $this->fsm->getTurnLimit(SessionState::INTAKE));
    }

    public function test_risk_triage_has_zero_turn_limit(): void
    {
        $this->assertEquals(0, $this->fsm->getTurnLimit(SessionState::RISK_TRIAGE));
    }

    public function test_exploration_has_two_turn_limit(): void
    {
        $this->assertEquals(2, $this->fsm->getTurnLimit(SessionState::EXPLORATION));
    }

    public function test_problem_solving_has_three_turn_limit(): void
    {
        $this->assertEquals(3, $this->fsm->getTurnLimit(SessionState::PROBLEM_SOLVING));
    }

    public function test_should_advance_when_turn_limit_reached(): void
    {
        $session = $this->createSession();
        $session->transitionTo(SessionState::EXPLORATION);

        $this->fsm->resetStateTurns($session);
        $this->assertFalse($this->fsm->shouldAdvance($session));

        $this->fsm->incrementStateTurns($session);
        $this->assertFalse($this->fsm->shouldAdvance($session));

        $this->fsm->incrementStateTurns($session);
        $this->assertTrue($this->fsm->shouldAdvance($session));
    }

    public function test_should_not_advance_before_turn_limit(): void
    {
        $session = $this->createSession();
        $session->transitionTo(SessionState::PROBLEM_SOLVING);

        $this->fsm->resetStateTurns($session);
        $this->fsm->incrementStateTurns($session);
        $this->fsm->incrementStateTurns($session);

        // At 2 turns, limit is 3
        $this->assertFalse($this->fsm->shouldAdvance($session));
    }

    // ==================== ADVANCE BEHAVIOR ====================

    public function test_advance_transitions_state(): void
    {
        $session = $this->createSession();
        $session->transitionTo(SessionState::EXPLORATION);

        $this->fsm->resetStateTurns($session);
        $this->fsm->incrementStateTurns($session);
        $this->fsm->incrementStateTurns($session);

        $advanced = $this->fsm->advance($session);

        $this->assertTrue($advanced);
        $this->assertEquals(SessionState::DEEPENING, $session->state);
    }

    public function test_advance_resets_turn_counter(): void
    {
        $session = $this->createSession();
        $session->transitionTo(SessionState::EXPLORATION);

        $this->fsm->resetStateTurns($session);
        $this->fsm->incrementStateTurns($session);
        $this->fsm->incrementStateTurns($session);
        $this->fsm->advance($session);

        $this->assertEquals(0, $this->fsm->getStateTurns($session));
    }

    public function test_advance_returns_false_when_not_ready(): void
    {
        $session = $this->createSession();
        $session->transitionTo(SessionState::EXPLORATION);

        $this->fsm->resetStateTurns($session);
        $this->fsm->incrementStateTurns($session);

        $this->assertFalse($this->fsm->advance($session));
        $this->assertEquals(SessionState::EXPLORATION, $session->state);
    }

    // ==================== GET NEXT STATE ====================

    public function test_get_next_state_returns_non_crisis_option(): void
    {
        // RISK_TRIAGE can go to EXPLORATION or CRISIS, but getNextState returns EXPLORATION
        $nextState = $this->fsm->getNextState(SessionState::RISK_TRIAGE);

        $this->assertEquals(SessionState::EXPLORATION, $nextState);
    }

    public function test_get_next_state_follows_normal_flow(): void
    {
        $this->assertEquals(
            SessionState::RISK_TRIAGE,
            $this->fsm->getNextState(SessionState::INTAKE)
        );
        $this->assertEquals(
            SessionState::DEEPENING,
            $this->fsm->getNextState(SessionState::EXPLORATION)
        );
        $this->assertEquals(
            SessionState::PROBLEM_SOLVING,
            $this->fsm->getNextState(SessionState::DEEPENING)
        );
        $this->assertEquals(
            SessionState::CLOSING,
            $this->fsm->getNextState(SessionState::PROBLEM_SOLVING)
        );
    }

    public function test_get_next_state_returns_null_for_closing(): void
    {
        $this->assertNull($this->fsm->getNextState(SessionState::CLOSING));
    }

    // ==================== STATE TURN TRACKING ====================

    public function test_increment_and_get_state_turns(): void
    {
        $session = $this->createSession();

        $this->assertEquals(0, $this->fsm->getStateTurns($session));

        $this->fsm->incrementStateTurns($session);
        $this->assertEquals(1, $this->fsm->getStateTurns($session));

        $this->fsm->incrementStateTurns($session);
        $this->assertEquals(2, $this->fsm->getStateTurns($session));
    }

    public function test_reset_state_turns(): void
    {
        $session = $this->createSession();

        $this->fsm->incrementStateTurns($session);
        $this->fsm->incrementStateTurns($session);
        $this->fsm->resetStateTurns($session);

        $this->assertEquals(0, $this->fsm->getStateTurns($session));
    }

    public function test_state_turns_persisted_on_session(): void
    {
        $session = $this->createSession();

        $this->fsm->incrementStateTurns($session);
        $this->fsm->incrementStateTurns($session);

        // Verify stateTurns is stored on the session object
        $this->assertEquals(2, $session->stateTurns);
    }

    public function test_transition_resets_state_turns(): void
    {
        $session = $this->createSession();

        $this->fsm->incrementStateTurns($session);
        $this->fsm->incrementStateTurns($session);
        $this->assertEquals(2, $session->stateTurns);

        // Transitioning to a new state resets stateTurns
        $session->transitionTo(SessionState::EXPLORATION);

        $this->assertEquals(0, $session->stateTurns);
    }

    // ==================== CUSTOM CONFIG ====================

    public function test_custom_turn_limits(): void
    {
        $customFsm = new StateMachine([
            'turn_limits' => [
                SessionState::EXPLORATION->value => 5,
                SessionState::PROBLEM_SOLVING->value => 10,
            ],
        ]);

        $this->assertEquals(5, $customFsm->getTurnLimit(SessionState::EXPLORATION));
        $this->assertEquals(10, $customFsm->getTurnLimit(SessionState::PROBLEM_SOLVING));
    }

    public function test_get_all_turn_limits(): void
    {
        $limits = $this->fsm->getAllTurnLimits();

        $this->assertArrayHasKey(SessionState::INTAKE->value, $limits);
        $this->assertArrayHasKey(SessionState::EXPLORATION->value, $limits);
        $this->assertArrayHasKey(SessionState::CRISIS_INTERVENTION->value, $limits);
    }

    // ==================== FULL FLOW SIMULATION ====================

    public function test_complete_session_flow(): void
    {
        $session = $this->createSession();

        // INTAKE -> RISK_TRIAGE (auto) -> EXPLORATION
        $this->fsm->incrementStateTurns($session);
        $this->fsm->advance($session);
        // Note: advance handles RISK_TRIAGE as pass-through
        $this->assertEquals(SessionState::EXPLORATION, $session->state);

        // EXPLORATION (2 turns) -> DEEPENING
        $this->fsm->incrementStateTurns($session);
        $this->assertFalse($this->fsm->shouldAdvance($session));
        $this->fsm->incrementStateTurns($session);
        $this->assertTrue($this->fsm->shouldAdvance($session));
        $this->fsm->advance($session);
        $this->assertEquals(SessionState::DEEPENING, $session->state);

        // DEEPENING (1 turn) -> PROBLEM_SOLVING
        $this->fsm->incrementStateTurns($session);
        $this->fsm->advance($session);
        $this->assertEquals(SessionState::PROBLEM_SOLVING, $session->state);

        // PROBLEM_SOLVING (3 turns) -> CLOSING
        $this->fsm->incrementStateTurns($session);
        $this->fsm->incrementStateTurns($session);
        $this->fsm->incrementStateTurns($session);
        $this->fsm->advance($session);
        $this->assertEquals(SessionState::CLOSING, $session->state);

        // CLOSING is terminal
        $this->assertTrue($this->fsm->isTerminal($session->state));
    }

    // ==================== HELPER METHODS ====================

    private function createSession(): Session
    {
        return Session::create(
            id: 'test-session-' . uniqid(),
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
            preferences: new SessionPreferences(),
        );
    }
}
