<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\SessionState;
use Sisly\Events\SessionEnded;
use Sisly\Events\StateTransitioned;
use Sisly\Facades\Sisly;
use Sisly\Tests\TestCase;

/**
 * v1.2.1 — Tests for the wall-clock time cap, the
 * end_on_terminal_state config flag, and the time-threshold
 * force-transition into CLOSING.
 *
 * These tests use the Sisly facade with the MockProvider LLM driver so
 * no API keys are required, and they manipulate the cached Session JSON
 * to simulate elapsed wall-clock time without actually waiting.
 */
class SislyManagerTimeCapTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Force MockProvider for deterministic LLM responses.
        $app['config']->set('sisly.llm.driver', 'mock');
        $app['config']->set('sisly.llm.failover_enabled', false);
    }

    // -----------------------------------------------------------------
    // Defaults preserved when the new flags are absent
    // -----------------------------------------------------------------

    public function test_max_session_seconds_null_preserves_v120_behaviour(): void
    {
        config()->set('sisly.fsm.max_session_seconds', null);

        $startResp = Sisly::startSession(
            message: 'Help me prepare for tomorrow.',
            context: ['geo' => new GeoContext(country: 'AE'), 'coach_id' => 'meetly'],
        );

        // Backdate the session by 1 hour — far past any reasonable cap.
        $this->backdateSession($startResp->sessionId, 3600);

        // With max_session_seconds=null, the wall-clock check is a no-op.
        // The session must NOT end with reason 'time_limit'.
        $resp = Sisly::message($startResp->sessionId, 'Still here.');

        $session = Sisly::getSession($startResp->sessionId);
        $this->assertNotNull($session, 'Session should still exist when time cap is disabled.');
        $this->assertTrue($session->isActive, 'Session must stay active when max_session_seconds=null.');
        $this->assertNotSame(SessionState::CLOSING, $session->state, 'No force-transition without a time cap.');
    }

    // -----------------------------------------------------------------
    // Time threshold force-transitions to CLOSING
    // -----------------------------------------------------------------

    public function test_time_threshold_forces_transition_to_closing(): void
    {
        config()->set('sisly.fsm.max_session_seconds', 600);
        config()->set('sisly.fsm.nearing_end_threshold', 0.85);
        // Disable auto-end so we can observe the forced CLOSING state.
        config()->set('sisly.fsm.end_on_terminal_state', false);

        $startResp = Sisly::startSession(
            message: 'I am uncertain about the future.',
            context: ['geo' => new GeoContext(country: 'AE'), 'coach_id' => 'safeo'],
        );

        // Past the threshold (0.85 * 600 = 510s), but not the full cap.
        $this->backdateSession($startResp->sessionId, 520);

        $events = $this->captureEvents([StateTransitioned::class, SessionEnded::class]);

        Sisly::message($startResp->sessionId, 'Still working through this.');

        $session = Sisly::getSession($startResp->sessionId);
        $this->assertNotNull($session);
        $this->assertTrue($session->isActive, 'Past threshold but under full cap — session stays alive.');
        $this->assertSame(SessionState::CLOSING, $session->state, 'Time threshold must force CLOSING.');
        $this->assertSame('time_threshold', $session->lastTransitionReason);

        // A StateTransitioned event for the force-transition should have fired.
        $transitionFired = false;
        foreach ($events->fired as $ev) {
            if ($ev instanceof StateTransitioned && $ev->toState === SessionState::CLOSING) {
                $transitionFired = true;
                break;
            }
        }
        $this->assertTrue($transitionFired, 'Force-transition must dispatch StateTransitioned.');
    }

    public function test_time_threshold_does_not_re_transition_when_already_in_closing(): void
    {
        config()->set('sisly.fsm.max_session_seconds', 600);
        config()->set('sisly.fsm.end_on_terminal_state', false);

        $startResp = Sisly::startSession(
            message: 'Help me with this decision.',
            context: ['geo' => new GeoContext(country: 'AE'), 'coach_id' => 'safeo'],
        );

        // Manually move the session to CLOSING in storage, then backdate.
        $this->mutateStoredSession($startResp->sessionId, function (array $data): array {
            $data['state'] = 'closing';
            $data['last_transition_reason'] = 'time_threshold';
            $data['last_transition_from_state'] = 'problem_solving';
            return $data;
        });
        $this->backdateSession($startResp->sessionId, 520);

        Sisly::message($startResp->sessionId, 'Still here.');

        $session = Sisly::getSession($startResp->sessionId);
        // No re-transition out of CLOSING (the FSM has nowhere to go from CLOSING anyway).
        $this->assertSame(SessionState::CLOSING, $session->state);
    }

    // -----------------------------------------------------------------
    // Time cap ends session with reason='time_limit'
    // -----------------------------------------------------------------

    public function test_full_time_cap_ends_session_with_time_limit_reason(): void
    {
        config()->set('sisly.fsm.max_session_seconds', 600);
        config()->set('sisly.fsm.end_on_terminal_state', false);

        $startResp = Sisly::startSession(
            message: 'I need help.',
            context: ['geo' => new GeoContext(country: 'AE'), 'coach_id' => 'meetly'],
        );

        // Past the full budget.
        $this->backdateSession($startResp->sessionId, 700);

        $events = $this->captureEvents([SessionEnded::class]);

        // The user still receives a final response BEFORE the session ends.
        $resp = Sisly::message($startResp->sessionId, 'One last thought.');
        $this->assertNotEmpty($resp->responseText, 'User must receive a final response before time-limit end.');

        $session = Sisly::getSession($startResp->sessionId);
        $this->assertNotNull($session, 'Session record persists even after time-limit end.');
        $this->assertFalse($session->isActive, 'Session must be inactive after time cap.');

        $endedWithTimeLimit = false;
        foreach ($events->fired as $ev) {
            if ($ev instanceof SessionEnded && $ev->endReason === 'time_limit') {
                $endedWithTimeLimit = true;
                break;
            }
        }
        $this->assertTrue($endedWithTimeLimit, 'SessionEnded event must fire with reason=time_limit.');
    }

    public function test_message_after_time_limit_throws_session_ended_exception(): void
    {
        config()->set('sisly.fsm.max_session_seconds', 600);

        $startResp = Sisly::startSession(
            message: 'Help me.',
            context: ['geo' => new GeoContext(country: 'AE'), 'coach_id' => 'meetly'],
        );

        $this->backdateSession($startResp->sessionId, 700);
        Sisly::message($startResp->sessionId, 'Final turn.');

        // Now the session is inactive. Next message must throw.
        $this->expectException(\Sisly\Exceptions\SislyException::class);
        $this->expectExceptionMessage('has ended');

        Sisly::message($startResp->sessionId, 'Trying again.');
    }

    // -----------------------------------------------------------------
    // end_on_terminal_state flag
    // -----------------------------------------------------------------

    public function test_end_on_terminal_state_true_preserves_v120_natural_end(): void
    {
        // True is the v1.2.0 / v1.2.1-default behaviour.
        config()->set('sisly.fsm.end_on_terminal_state', true);
        // Disable the time cap so we isolate the natural-end behaviour.
        config()->set('sisly.fsm.max_session_seconds', null);

        $startResp = Sisly::startSession(
            message: 'I have a meeting tomorrow.',
            context: ['geo' => new GeoContext(country: 'AE'), 'coach_id' => 'meetly'],
        );

        // Manually move the session into CLOSING in storage.
        $this->mutateStoredSession($startResp->sessionId, function (array $data): array {
            $data['state'] = 'closing';
            return $data;
        });

        // Sending another message in CLOSING state with end_on_terminal=true ends the session.
        Sisly::message($startResp->sessionId, 'Thanks.');

        $session = Sisly::getSession($startResp->sessionId);
        $this->assertNotNull($session);
        $this->assertFalse($session->isActive, 'end_on_terminal_state=true must end session in CLOSING.');
    }

    public function test_end_on_terminal_state_false_keeps_session_alive_in_closing(): void
    {
        config()->set('sisly.fsm.end_on_terminal_state', false);
        config()->set('sisly.fsm.max_session_seconds', null);

        $startResp = Sisly::startSession(
            message: 'I have a meeting tomorrow.',
            context: ['geo' => new GeoContext(country: 'AE'), 'coach_id' => 'meetly'],
        );

        $this->mutateStoredSession($startResp->sessionId, function (array $data): array {
            $data['state'] = 'closing';
            return $data;
        });

        // Multiple turns in CLOSING — session should stay alive throughout.
        for ($i = 0; $i < 3; $i++) {
            Sisly::message($startResp->sessionId, "Closing turn {$i}.");
            $session = Sisly::getSession($startResp->sessionId);
            $this->assertTrue(
                $session->isActive,
                "end_on_terminal_state=false: session must stay alive in CLOSING (turn {$i})."
            );
            $this->assertSame(SessionState::CLOSING, $session->state);
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Backdate a session's createdAt by N seconds by mutating the cache.
     */
    private function backdateSession(string $sessionId, int $secondsAgo): void
    {
        $this->mutateStoredSession($sessionId, function (array $data) use ($secondsAgo): array {
            $newCreatedAt = (new \DateTimeImmutable())->modify("-{$secondsAgo} seconds");
            $data['created_at'] = $newCreatedAt->format('c');
            return $data;
        });
    }

    /**
     * Mutate a stored session's array form via a callback, then save it back.
     */
    private function mutateStoredSession(string $sessionId, callable $mutator): void
    {
        $cacheKey = 'sisly:session:' . $sessionId;
        $data = Cache::get($cacheKey);
        $this->assertNotNull($data, "Session {$sessionId} must be in cache.");

        $data = $mutator($data);

        Cache::put($cacheKey, $data, 1800);
    }

    /**
     * Capture events of the given classes for assertion.
     *
     * @param array<class-string> $classes
     */
    private function captureEvents(array $classes): object
    {
        $captured = new class {
            /** @var list<object> */
            public array $fired = [];
        };

        foreach ($classes as $class) {
            \Illuminate\Support\Facades\Event::listen($class, function ($event) use ($captured): void {
                $captured->fired[] = $event;
            });
        }

        return $captured;
    }
}
