<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Sisly\DTOs\ConversationTurn;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

class SessionTest extends TestCase
{
    public function test_can_create_new_session(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $this->assertEquals('test-session-id', $session->id);
        $this->assertEquals(CoachId::MEETLY, $session->coachId);
        $this->assertEquals(SessionState::INTAKE, $session->state);
        $this->assertEquals(0, $session->turnCount);
        $this->assertTrue($session->isActive);
        $this->assertEmpty($session->history);
    }

    public function test_add_turn_increments_count(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $session->addTurn(ConversationTurn::user('Hello'));

        $this->assertEquals(1, $session->turnCount);
        $this->assertCount(1, $session->history);
    }

    public function test_history_is_pruned_at_max_turns(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        // Add 25 turns (more than max of 20)
        for ($i = 1; $i <= 25; $i++) {
            $session->addTurn(ConversationTurn::user("Message {$i}"));
        }

        // History should be limited to 20
        $this->assertCount(20, $session->history);
        // Turn count should reflect all turns
        $this->assertEquals(25, $session->turnCount);
        // First message should be pruned (FIFO)
        $this->assertEquals('Message 6', $session->history[0]->content);
    }

    public function test_transition_to_changes_state(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $session->transitionTo(SessionState::EXPLORATION);

        $this->assertEquals(SessionState::EXPLORATION, $session->state);
    }

    public function test_end_deactivates_session(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $session->end();

        $this->assertFalse($session->isActive);
    }

    public function test_get_last_user_message(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $session->addTurn(ConversationTurn::user('First message'));
        $session->addTurn(ConversationTurn::assistant('Response'));
        $session->addTurn(ConversationTurn::user('Second message'));

        $this->assertEquals('Second message', $session->getLastUserMessage());
    }

    public function test_get_history_for_llm_returns_simplified_format(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $session->addTurn(ConversationTurn::user('Hello'));
        $session->addTurn(ConversationTurn::assistant('Hi there'));

        $history = $session->getHistoryForLLM();

        $this->assertEquals([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there'],
        ], $history);
    }

    public function test_to_array_and_from_array_round_trip(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE', city: 'Dubai'),
            preferences: new SessionPreferences(language: 'ar'),
        );
        $session->addTurn(ConversationTurn::user('Test message'));

        $array = $session->toArray();
        $restored = Session::fromArray($array);

        $this->assertEquals($session->id, $restored->id);
        $this->assertEquals($session->coachId, $restored->coachId);
        $this->assertEquals($session->state, $restored->state);
        $this->assertEquals($session->turnCount, $restored->turnCount);
        $this->assertEquals($session->geo->country, $restored->geo->country);
        $this->assertEquals($session->preferences->language, $restored->preferences->language);
        $this->assertCount(1, $restored->history);
    }

    // -----------------------------------------------------------------
    // v1.2.1 — configurable history cap + transition tracking
    // -----------------------------------------------------------------

    public function test_default_max_history_turns_is_20_for_back_compat(): void
    {
        $session = Session::create(
            id: 'test',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $this->assertSame(20, $session->maxHistoryTurns);
        $this->assertSame(20, Session::DEFAULT_MAX_HISTORY_TURNS);
    }

    public function test_max_history_turns_is_honored_in_addTurn_fifo_trim(): void
    {
        $session = Session::create(
            id: 'test',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
            maxHistoryTurns: 5,
        );

        for ($i = 1; $i <= 10; $i++) {
            $session->addTurn(ConversationTurn::user("Message {$i}"));
        }

        // Cap of 5 means only the last 5 entries survive.
        $this->assertCount(5, $session->history);
        $this->assertEquals(10, $session->turnCount);
        $this->assertEquals('Message 6', $session->history[0]->content);
        $this->assertEquals('Message 10', $session->history[4]->content);
    }

    public function test_larger_max_history_turns_keeps_more_entries(): void
    {
        $session = Session::create(
            id: 'test',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
            maxHistoryTurns: 60,
        );

        for ($i = 1; $i <= 50; $i++) {
            $session->addTurn(ConversationTurn::user("Message {$i}"));
        }

        // 50 < cap of 60, so nothing is trimmed.
        $this->assertCount(50, $session->history);
        $this->assertEquals('Message 1', $session->history[0]->content);
    }

    public function test_last_transition_at_starts_at_zero(): void
    {
        $session = Session::create(
            id: 'test',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $this->assertSame(0, $session->lastTransitionAt);
        $this->assertNull($session->lastTransitionFromState);
        $this->assertNull($session->lastTransitionReason);
    }

    public function test_transition_to_records_from_state_and_turn_count(): void
    {
        $session = Session::create(
            id: 'test',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $session->addTurn(ConversationTurn::user('Hello'));
        $session->addTurn(ConversationTurn::assistant('Hi'));
        // turnCount is now 2

        $session->transitionTo(SessionState::EXPLORATION);

        $this->assertEquals(SessionState::EXPLORATION, $session->state);
        $this->assertSame(2, $session->lastTransitionAt);
        $this->assertEquals(SessionState::INTAKE, $session->lastTransitionFromState);
        $this->assertNull($session->lastTransitionReason);
    }

    public function test_transition_to_records_optional_reason(): void
    {
        $session = Session::create(
            id: 'test',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );
        $session->addTurn(ConversationTurn::user('Hello'));

        $session->transitionTo(SessionState::CLOSING, reason: 'time_threshold');

        $this->assertSame('time_threshold', $session->lastTransitionReason);
    }

    public function test_to_array_includes_v121_fields(): void
    {
        $session = Session::create(
            id: 'test',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
            maxHistoryTurns: 40,
        );
        $session->addTurn(ConversationTurn::user('Hello'));
        $session->transitionTo(SessionState::EXPLORATION, reason: 'time_threshold');

        $array = $session->toArray();

        $this->assertArrayHasKey('max_history_turns', $array);
        $this->assertArrayHasKey('last_transition_at', $array);
        $this->assertArrayHasKey('last_transition_from_state', $array);
        $this->assertArrayHasKey('last_transition_reason', $array);

        $this->assertSame(40, $array['max_history_turns']);
        $this->assertSame(1, $array['last_transition_at']);
        $this->assertSame('intake', $array['last_transition_from_state']);
        $this->assertSame('time_threshold', $array['last_transition_reason']);
    }

    public function test_from_array_falls_back_to_defaults_for_pre_v121_cached_sessions(): void
    {
        // Simulate a session JSON written by v1.2.0 — no v1.2.1 fields.
        $array = [
            'id' => 'old-session',
            'coach_id' => 'meetly',
            'state' => 'exploration',
            'turn_count' => 5,
            'state_turns' => 1,
            'geo' => (new GeoContext(country: 'AE'))->toArray(),
            'preferences' => (new SessionPreferences())->toArray(),
            'history' => [],
            'crisis' => \Sisly\DTOs\CrisisInfo::none()->toArray(),
            'is_active' => true,
            'created_at' => (new \DateTimeImmutable())->format('c'),
            'last_activity' => (new \DateTimeImmutable())->format('c'),
        ];

        $session = Session::fromArray($array);

        $this->assertSame(20, $session->maxHistoryTurns);
        $this->assertSame(0, $session->lastTransitionAt);
        $this->assertNull($session->lastTransitionFromState);
        $this->assertNull($session->lastTransitionReason);
    }

    public function test_round_trip_preserves_v121_fields(): void
    {
        $session = Session::create(
            id: 'test',
            coachId: CoachId::SAFEO,
            geo: new GeoContext(country: 'AE'),
            maxHistoryTurns: 40,
        );
        $session->addTurn(ConversationTurn::user('Hello'));
        $session->addTurn(ConversationTurn::assistant('Hi'));
        $session->transitionTo(SessionState::EXPLORATION);
        $session->addTurn(ConversationTurn::user('Tell me more'));
        $session->transitionTo(SessionState::CLOSING, reason: 'time_threshold');

        $restored = Session::fromArray($session->toArray());

        $this->assertSame(40, $restored->maxHistoryTurns);
        $this->assertSame($session->lastTransitionAt, $restored->lastTransitionAt);
        $this->assertEquals(SessionState::EXPLORATION, $restored->lastTransitionFromState);
        $this->assertSame('time_threshold', $restored->lastTransitionReason);
    }
}
