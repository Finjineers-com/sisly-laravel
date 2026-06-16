<?php

declare(strict_types=1);

namespace Sisly\DTOs;

/**
 * Data representing a media content prescription resolved with real asset details.
 */
final class ResolvedPrescription
{
    public function __construct(
        public readonly int $contentId,
        public readonly string $title,
        public readonly string $description,
        public readonly int $duration,
        public readonly string $mediaCategory,
        public readonly string $audioPath,
        public readonly string $audioThumbnail,
        public readonly string $reason,
    ) {}

    /**
     * Create instance from array.
     *
     * @param array{content_id: int, title: string, description: string, duration: int, media_category: string, audio_path: string, audio_thumbnail: string, reason: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            contentId: $data['content_id'],
            title: $data['title'],
            description: $data['description'],
            duration: $data['duration'],
            mediaCategory: $data['media_category'],
            audioPath: $data['audio_path'],
            audioThumbnail: $data['audio_thumbnail'],
            reason: $data['reason'],
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array{content_id: int, title: string, description: string, duration: int, media_category: string, audio_path: string, audio_thumbnail: string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'content_id' => $this->contentId,
            'title' => $this->title,
            'description' => $this->description,
            'duration' => $this->duration,
            'media_category' => $this->mediaCategory,
            'audio_path' => $this->audioPath,
            'audio_thumbnail' => $this->audioThumbnail,
            'reason' => $this->reason,
        ];
    }
}
