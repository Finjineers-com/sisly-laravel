<?php

declare(strict_types=1);

namespace Sisly\DTOs;

use DateTimeImmutable;
use Sisly\Enums\CoachId;

/**
 * Rolling per-session state aligned to the functional spec.
 *
 * Spec defines the memory model as:
 * { coach_id, locale, turn, situation_summary, current_mood, target_mood, last_2_messages }
 *
 * This is sent to the model (not the full transcript) keeping context compact.
 * Cross-session memory defaults to OFF — each new session starts fresh.
 */
final class CoachState
{
    /**
     * Valid moods per spec.
     */
    public const MOODS = ['Excited', 'Happy', 'Calm', 'Anxious', 'Sad'];

    /**
     * Valid content types for prescription handoff.
     */
    public const CONTENT_TYPES = ['Meditation', 'DoWithMe', 'Affirmation', 'Sound', 'Podcast'];

    /**
     * @param array<array{role: string, content: string}> $lastMessages Rolling window (last 2 exchanges)
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly CoachId $coachId,
        public readonly string $locale,              // 'en' | 'ar'
        public int $turn,
        public ?string $situationSummary,            // One-line memory — the summary IS the memory
        public ?string $currentMood,                 // One of the 5 locked moods
        public ?string $targetMood,                  // One of the 5 locked moods
        public array $lastMessages,                  // Rolling window (last 2 exchanges)
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Create a new CoachState for a fresh session.
     */
    public static function create(
        string $sessionId,
        CoachId $coachId,
        string $locale = 'en',
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            sessionId: $sessionId,
            coachId: $coachId,
            locale: $locale,
            turn: 0,
            situationSummary: null,
            currentMood: null,
            targetMood: null,
            lastMessages: [],
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Advance the turn counter and update the rolling message window.
     *
     * @param array{role: string, content: string} $userMessage
     * @param array{role: string, content: string} $assistantMessage
     */
    public function advance(array $userMessage, array $assistantMessage): void
    {
        $this->turn++;
        $this->updatedAt = new DateTimeImmutable();

        // Rolling window: keep only the last 2 exchanges (spec: "last_2_messages")
        $this->lastMessages = [
            $userMessage,
            $assistantMessage,
        ];
    }

    /**
     * Update the situation summary (one-line memory).
     */
    public function updateSummary(string $summary): void
    {
        $this->situationSummary = $summary;
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Update mood state from prescription data.
     */
    public function updateMoods(?string $currentMood, ?string $targetMood): void
    {
        if ($currentMood !== null && in_array($currentMood, self::MOODS, true)) {
            $this->currentMood = $currentMood;
        }
        if ($targetMood !== null && in_array($targetMood, self::MOODS, true)) {
            $this->targetMood = $targetMood;
        }
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Build the compact context sent to the model.
     * Spec: "Send only the rolling state plus the last exchange to the model,
     * never the full transcript."
     *
     * @return array<string, mixed>
     */
    public function toModelContext(): array
    {
        return [
            'coach_id'           => $this->coachId->value,
            'locale'             => $this->locale,
            'turn'               => $this->turn,
            'situation_summary'  => $this->situationSummary ?? '',
            'current_mood'       => $this->currentMood ?? 'unknown',
            'target_mood'        => $this->targetMood ?? 'unknown',
            'last_2_messages'    => $this->lastMessages,
        ];
    }

    /**
     * Serialize for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id'         => $this->sessionId,
            'coach_id'           => $this->coachId->value,
            'locale'             => $this->locale,
            'turn'               => $this->turn,
            'situation_summary'  => $this->situationSummary,
            'current_mood'       => $this->currentMood,
            'target_mood'        => $this->targetMood,
            'last_messages'      => $this->lastMessages,
            'created_at'         => $this->createdAt->format('c'),
            'updated_at'         => $this->updatedAt->format('c'),
        ];
    }

    /**
     * Deserialize from storage.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sessionId: $data['session_id'],
            coachId: CoachId::from($data['coach_id']),
            locale: $data['locale'] ?? 'en',
            turn: $data['turn'] ?? 0,
            situationSummary: $data['situation_summary'] ?? null,
            currentMood: $data['current_mood'] ?? null,
            targetMood: $data['target_mood'] ?? null,
            lastMessages: $data['last_messages'] ?? [],
            createdAt: new DateTimeImmutable($data['created_at']),
            updatedAt: new DateTimeImmutable($data['updated_at']),
        );
    }
}
