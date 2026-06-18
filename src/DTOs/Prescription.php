<?php

declare(strict_types=1);

namespace Sisly\DTOs;

/**
 * Content prescription emitted by the coach at handoff.
 *
 * Spec format:
 * ```sisly
 * { "content_type": "Meditation|DoWithMe|Affirmation|Sound|Podcast",
 *   "current_mood": "Excited|Happy|Calm|Anxious|Sad",
 *   "target_mood":  "Excited|Happy|Calm|Anxious|Sad",
 *   "reason": "one warm line, in the person's language" }
 * ```
 *
 * Keys and enum values stay in English (machine contract).
 * Only the `reason` value is in the user's language.
 */
final class Prescription
{
    public function __construct(
        public readonly string $contentType,   // Meditation|DoWithMe|Affirmation|Sound|Podcast
        public readonly string $currentMood,   // Excited|Happy|Calm|Anxious|Sad
        public readonly string $targetMood,    // Excited|Happy|Calm|Anxious|Sad
        public readonly string $reason,        // One warm line in user's language
        public readonly ?string $assetId = null,    // Resolved asset ID (if asset resolver configured)
        public readonly ?string $assetUrl = null,   // Resolved streaming URL
    ) {}

    /**
     * Parse from a ```sisly code block extracted from a coach response.
     *
     * Returns null if the block is absent or fails validation.
     * Spec: "If parsing fails, silently drop the prescription and return
     * the cleaned text. The coach will try again next turn."
     */
    public static function fromSislyBlock(string $text): ?self
    {
        $match = self::extractBlock($text);
        if ($match === null) {
            return null;
        }

        try {
            $data = json_decode($match, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $contentType = $data['content_type'] ?? null;
        $currentMood = $data['current_mood'] ?? null;
        $targetMood  = $data['target_mood'] ?? null;
        $reason      = $data['reason'] ?? null;

        // Validate enums
        if (!in_array($contentType, CoachState::CONTENT_TYPES, true)) {
            return null;
        }
        if (!in_array($currentMood, CoachState::MOODS, true)) {
            return null;
        }
        if (!in_array($targetMood, CoachState::MOODS, true)) {
            return null;
        }
        if (empty($reason) || !is_string($reason)) {
            return null;
        }

        return new self(
            contentType: $contentType,
            currentMood: $currentMood,
            targetMood: $targetMood,
            reason: $reason,
        );
    }

    /**
     * Strip the ```sisly block from text, returning the clean coach message.
     */
    public static function stripBlock(string $text): string
    {
        return trim(preg_replace('/```sisly\s*[\s\S]*?```/', '', $text) ?? $text);
    }

    /**
     * Extract the JSON from inside a ```sisly block.
     */
    private static function extractBlock(string $text): ?string
    {
        if (preg_match('/```sisly\s*([\s\S]*?)```/', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content_type'  => $this->contentType,
            'current_mood'  => $this->currentMood,
            'target_mood'   => $this->targetMood,
            'reason'        => $this->reason,
            'asset_id'      => $this->assetId,
            'asset_url'     => $this->assetUrl,
        ];
    }
}
