<?php

declare(strict_types=1);

namespace Sisly\DTOs;

/**
 * Chain of Empathy reasoning trace.
 */
final class CoETrace
{
    public function __construct(
        public readonly string $emotionPrimary,
        public readonly ?string $emotionSecondary,
        public readonly string $causeAnalysis,
        public readonly string $userIntent,        // "validation", "advice", "venting"
        public readonly string $strategySelected,   // "validation", "exploration", etc.
        public readonly string $draftResponse,
    ) {}

    /**
     * Create instance from array.
     *
     * @param array{emotion_primary: string, emotion_secondary?: string|null, cause_analysis: string, user_intent: string, strategy_selected: string, draft_response: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            emotionPrimary: $data['emotion_primary'],
            emotionSecondary: $data['emotion_secondary'] ?? null,
            causeAnalysis: $data['cause_analysis'],
            userIntent: $data['user_intent'],
            strategySelected: $data['strategy_selected'],
            draftResponse: $data['draft_response'],
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array{emotion_primary: string, emotion_secondary: string|null, cause_analysis: string, user_intent: string, strategy_selected: string, draft_response: string}
     */
    public function toArray(): array
    {
        return [
            'emotion_primary' => $this->emotionPrimary,
            'emotion_secondary' => $this->emotionSecondary,
            'cause_analysis' => $this->causeAnalysis,
            'user_intent' => $this->userIntent,
            'strategy_selected' => $this->strategySelected,
            'draft_response' => $this->draftResponse,
        ];
    }
}
