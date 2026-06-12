<?php

declare(strict_types=1);

namespace Sisly\DTOs;

use DateTimeImmutable;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * Session data stored between turns.
 */
final class Session
{
    /**
     * Default history cap when not provided via config. Older cached sessions
     * deserialized via fromArray() also fall back to this value.
     */
    public const DEFAULT_MAX_HISTORY_TURNS = 20;

    /**
     * @param array<ConversationTurn> $history
     * @param int $maxHistoryTurns FIFO cap on $history length. Read from
     *        session.max_history_turns config at session-creation time.
     * @param int $lastTransitionAt $turnCount value at the moment of the
     *        most recent FSM state transition. Used by BaseCoach to append
     *        a one-turn "transition bridge" to the system prompt for the
     *        turn immediately following a transition. 0 means no transition
     *        has occurred yet.
     * @param ?SessionState $lastTransitionFromState The state the session
     *        was in immediately before the most recent transition. Combined
     *        with $state and $lastTransitionReason it identifies which
     *        bridge in global/transitions.md to use.
     * @param ?string $lastTransitionReason Optional qualifier for the most
     *        recent transition. Currently the only recognised value is
     *        'time_threshold' (used when SislyManager force-transitions to
     *        CLOSING because the wall-clock budget is approaching exhaustion).
     */
    public function __construct(
        public readonly string $id,
        public CoachId $coachId,
        public SessionState $state,
        public int $turnCount,
        public int $stateTurns,
        public readonly GeoContext $geo,
        public readonly SessionPreferences $preferences,
        public array $history,
        public CrisisInfo $crisis,
        public bool $isActive,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastActivity,
        public readonly int $maxHistoryTurns = self::DEFAULT_MAX_HISTORY_TURNS,
        public int $lastTransitionAt = 0,
        public ?SessionState $lastTransitionFromState = null,
        public ?string $lastTransitionReason = null,
    ) {}

    /**
     * Create a new session.
     */
    public static function create(
        string $id,
        CoachId $coachId,
        GeoContext $geo,
        ?SessionPreferences $preferences = null,
        int $maxHistoryTurns = self::DEFAULT_MAX_HISTORY_TURNS,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            coachId: $coachId,
            state: SessionState::INTAKE,
            turnCount: 0,
            stateTurns: 0,
            geo: $geo,
            preferences: $preferences ?? new SessionPreferences(),
            history: [],
            crisis: CrisisInfo::none(),
            isActive: true,
            createdAt: $now,
            lastActivity: $now,
            maxHistoryTurns: $maxHistoryTurns,
            lastTransitionAt: 0,
        );
    }

    /**
     * Add a conversation turn to the history.
     */
    public function addTurn(ConversationTurn $turn): void
    {
        $this->history[] = $turn;
        $this->turnCount++;
        $this->lastActivity = new DateTimeImmutable();

        // Enforce max history (FIFO pruning)
        if (count($this->history) > $this->maxHistoryTurns) {
            array_shift($this->history);
        }
    }

    /**
     * Transition to a new state.
     *
     * The optional $reason qualifier is recorded for use by transition
     * bridges (see global/transitions.md). The only currently recognised
     * value is 'time_threshold' for force-transitions into CLOSING driven
     * by the wall-clock cap.
     */
    public function transitionTo(SessionState $newState, ?string $reason = null): void
    {
        $this->lastTransitionFromState = $this->state;
        $this->lastTransitionReason = $reason;
        $this->state = $newState;
        $this->stateTurns = 0; // Reset turn counter for new state
        $this->lastActivity = new DateTimeImmutable();
        $this->lastTransitionAt = $this->turnCount;
    }

    /**
     * Mark the session as ended.
     */
    public function end(): void
    {
        $this->isActive = false;
        $this->lastActivity = new DateTimeImmutable();
    }

    /**
     * Set crisis information.
     */
    public function setCrisis(CrisisInfo $crisis): void
    {
        $this->crisis = $crisis;
        if ($crisis->detected) {
            $this->state = SessionState::CRISIS_INTERVENTION;
        }
    }

    /**
     * Get the most recent user message.
     */
    public function getLastUserMessage(): ?string
    {
        for ($i = count($this->history) - 1; $i >= 0; $i--) {
            if ($this->history[$i]->isUser()) {
                return $this->history[$i]->content;
            }
        }
        return null;
    }

    /**
     * Get conversation history for LLM context.
     *
     * @return array<array{role: string, content: string}>
     */
    public function getHistoryForLLM(): array
    {
        return array_map(
            fn (ConversationTurn $turn) => [
                'role' => $turn->role,
                'content' => $turn->content,
            ],
            $this->history
        );
    }

    /**
     * Create instance from array (for deserialization).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            coachId: CoachId::from($data['coach_id']),
            state: SessionState::from($data['state']),
            turnCount: $data['turn_count'],
            stateTurns: $data['state_turns'] ?? 0,
            geo: GeoContext::fromArray($data['geo']),
            preferences: SessionPreferences::fromArray($data['preferences']),
            history: array_map(
                fn (array $turn) => ConversationTurn::fromArray($turn),
                $data['history']
            ),
            crisis: CrisisInfo::fromArray($data['crisis']),
            isActive: $data['is_active'],
            createdAt: new DateTimeImmutable($data['created_at']),
            lastActivity: new DateTimeImmutable($data['last_activity']),
            maxHistoryTurns: $data['max_history_turns'] ?? self::DEFAULT_MAX_HISTORY_TURNS,
            lastTransitionAt: $data['last_transition_at'] ?? 0,
            lastTransitionFromState: isset($data['last_transition_from_state'])
                ? SessionState::from($data['last_transition_from_state'])
                : null,
            lastTransitionReason: $data['last_transition_reason'] ?? null,
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'coach_id' => $this->coachId->value,
            'state' => $this->state->value,
            'turn_count' => $this->turnCount,
            'state_turns' => $this->stateTurns,
            'geo' => $this->geo->toArray(),
            'preferences' => $this->preferences->toArray(),
            'history' => array_map(
                fn (ConversationTurn $turn) => $turn->toArray(),
                $this->history
            ),
            'crisis' => $this->crisis->toArray(),
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt->format('c'),
            'last_activity' => $this->lastActivity->format('c'),
            'max_history_turns' => $this->maxHistoryTurns,
            'last_transition_at' => $this->lastTransitionAt,
            'last_transition_from_state' => $this->lastTransitionFromState?->value,
            'last_transition_reason' => $this->lastTransitionReason,
        ];
    }
}
