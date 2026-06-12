<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Coaches;

use PHPUnit\Framework\TestCase;
use Sisly\Coaches\MeetlyCoach;
use Sisly\Coaches\PromptLoader;
use Sisly\DTOs\ConversationTurn;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\LLM\MockProvider;

/**
 * v1.2.1 — Tests the transition-bridge mechanism that prevents abrupt
 * tone shifts between FSM states.
 *
 * The bridge fires for ONE TURN only, immediately after a state
 * transition, and is loaded from global/transitions.md by
 * PromptLoader::loadTransitionBridge() and appended by
 * BaseCoach::buildFullSystemPrompt().
 */
class TransitionBridgeTest extends TestCase
{
    private PromptLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = new PromptLoader();
    }

    // -----------------------------------------------------------------
    // PromptLoader::loadTransitionBridge — section resolution
    // -----------------------------------------------------------------

    public function test_loads_exploration_to_deepening_bridge(): void
    {
        $bridge = $this->loader->loadTransitionBridge(
            from: SessionState::EXPLORATION,
            to: SessionState::DEEPENING,
        );

        $this->assertNotEmpty($bridge);
        $this->assertStringContainsString('exploration into deepening', strtolower($bridge));
    }

    public function test_loads_problem_solving_to_closing_bridge(): void
    {
        $bridge = $this->loader->loadTransitionBridge(
            from: SessionState::PROBLEM_SOLVING,
            to: SessionState::CLOSING,
        );

        $this->assertNotEmpty($bridge);
        $this->assertStringContainsString('closing', strtolower($bridge));
    }

    public function test_time_threshold_reason_overrides_default_lookup(): void
    {
        $defaultBridge = $this->loader->loadTransitionBridge(
            from: SessionState::PROBLEM_SOLVING,
            to: SessionState::CLOSING,
        );
        $timeBridge = $this->loader->loadTransitionBridge(
            from: SessionState::PROBLEM_SOLVING,
            to: SessionState::CLOSING,
            reason: 'time_threshold',
        );

        $this->assertNotEmpty($timeBridge);
        $this->assertNotSame($defaultBridge, $timeBridge, 'time_threshold must use a different bridge.');

        // The time-threshold bridge MUST NOT mention the time limit to the
        // user. The bridge instructs the bot how to behave — and that
        // instruction itself contains directives like "Without naming any
        // time limit". That phrase is a regression guard.
        $this->assertStringContainsString('without naming', strtolower($timeBridge));
    }

    public function test_time_threshold_works_from_any_pre_closing_state(): void
    {
        // The user might hit the time threshold while still in EXPLORATION
        // or DEEPENING. The any_to_closing_time_threshold bridge should
        // apply regardless of the from-state.
        foreach ([SessionState::EXPLORATION, SessionState::DEEPENING, SessionState::PROBLEM_SOLVING] as $from) {
            $bridge = $this->loader->loadTransitionBridge(
                from: $from,
                to: SessionState::CLOSING,
                reason: 'time_threshold',
            );
            $this->assertNotEmpty($bridge, "time_threshold bridge must apply from {$from->value}.");
            $this->assertStringContainsString('without naming', strtolower($bridge));
        }
    }

    public function test_unknown_pair_returns_empty_string(): void
    {
        // CRISIS_INTERVENTION is a trap state — no bridges should apply.
        $bridge = $this->loader->loadTransitionBridge(
            from: SessionState::EXPLORATION,
            to: SessionState::CRISIS_INTERVENTION,
        );
        $this->assertSame('', $bridge);
    }

    public function test_risk_triage_does_not_get_a_bridge(): void
    {
        // RISK_TRIAGE is an automatic pass-through state.
        $bridge = $this->loader->loadTransitionBridge(
            from: SessionState::INTAKE,
            to: SessionState::RISK_TRIAGE,
        );
        $this->assertSame('', $bridge);
    }

    // -----------------------------------------------------------------
    // BaseCoach integration — bridge appears in system prompt only on
    // the turn immediately following a transition
    // -----------------------------------------------------------------

    public function test_bridge_appended_when_transition_occurred_on_previous_turn(): void
    {
        $coach = new MeetlyCoach(new MockProvider());
        $session = $this->buildSessionAtTurn(turnCount: 5);

        // Simulate: a transition just happened at turnCount=5, then the
        // user sent a new message which incremented turnCount to 6.
        $session->lastTransitionAt = 5;
        $session->lastTransitionFromState = SessionState::EXPLORATION;
        $session->state = SessionState::DEEPENING;
        $session->turnCount = 6;

        $prompt = $this->invokeBuildFullSystemPrompt($coach, $session);

        $this->assertStringContainsString('## Transition Bridge', $prompt);
        $this->assertStringContainsString('exploration into deepening', strtolower($prompt));
    }

    public function test_bridge_not_appended_two_turns_after_transition(): void
    {
        $coach = new MeetlyCoach(new MockProvider());
        $session = $this->buildSessionAtTurn(turnCount: 8);

        // Transition was at turnCount=5; we're now at turnCount=8 → bridge stale.
        $session->lastTransitionAt = 5;
        $session->lastTransitionFromState = SessionState::EXPLORATION;
        $session->state = SessionState::DEEPENING;

        $prompt = $this->invokeBuildFullSystemPrompt($coach, $session);

        $this->assertStringNotContainsString('## Transition Bridge', $prompt);
    }

    public function test_bridge_not_appended_on_first_turn_no_transition_yet(): void
    {
        $coach = new MeetlyCoach(new MockProvider());
        $session = $this->buildSessionAtTurn(turnCount: 1);
        // Default: lastTransitionAt=0, lastTransitionFromState=null.

        $prompt = $this->invokeBuildFullSystemPrompt($coach, $session);

        $this->assertStringNotContainsString('## Transition Bridge', $prompt);
    }

    public function test_force_closing_bridge_is_used_when_reason_is_time_threshold(): void
    {
        $coach = new MeetlyCoach(new MockProvider());
        $session = $this->buildSessionAtTurn(turnCount: 12);

        $session->lastTransitionAt = 11;
        $session->lastTransitionFromState = SessionState::PROBLEM_SOLVING;
        $session->lastTransitionReason = 'time_threshold';
        $session->state = SessionState::CLOSING;
        $session->turnCount = 12;

        $prompt = $this->invokeBuildFullSystemPrompt($coach, $session);

        $this->assertStringContainsString('## Transition Bridge', $prompt);
        $this->assertStringContainsString('without naming', strtolower($prompt));
    }

    public function test_unknown_transition_pair_does_not_break_prompt(): void
    {
        $coach = new MeetlyCoach(new MockProvider());
        $session = $this->buildSessionAtTurn(turnCount: 3);

        // From->to pair not in transitions.md — no bridge should be appended,
        // and the prompt should still build successfully.
        $session->lastTransitionAt = 2;
        $session->lastTransitionFromState = SessionState::INTAKE;
        $session->state = SessionState::PROBLEM_SOLVING; // not a known pair
        $session->turnCount = 3;

        $prompt = $this->invokeBuildFullSystemPrompt($coach, $session);

        $this->assertNotEmpty($prompt);
        $this->assertStringNotContainsString('## Transition Bridge', $prompt);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function buildSessionAtTurn(int $turnCount): Session
    {
        $session = Session::create(
            id: 'test-bridge',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
            preferences: new SessionPreferences(),
        );
        $session->turnCount = $turnCount;
        return $session;
    }

    /**
     * BaseCoach::buildFullSystemPrompt is protected. Use reflection to
     * invoke it for prompt-content assertions.
     */
    private function invokeBuildFullSystemPrompt(MeetlyCoach $coach, Session $session): string
    {
        $reflection = new \ReflectionClass($coach);
        $method = $reflection->getMethod('buildFullSystemPrompt');
        $method->setAccessible(true);
        /** @var string $prompt */
        $prompt = $method->invoke($coach, $session);
        return $prompt;
    }
}
