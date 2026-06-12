<?php

declare(strict_types=1);

namespace Sisly\DTOs;

use DateTimeImmutable;

/**
 * A single conversation turn.
 */
final class ConversationTurn
{
    public function __construct(
        public readonly string $role,        // "user" or "assistant"
        public readonly string $content,
        public readonly DateTimeImmutable $timestamp,
        public readonly ?CoETrace $coeTrace = null,
    ) {}

    /**
     * Create a user turn.
     */
    public static function user(string $content, ?DateTimeImmutable $timestamp = null): self
    {
        return new self(
            role: 'user',
            content: $content,
            timestamp: $timestamp ?? new DateTimeImmutable(),
        );
    }

    /**
     * Create an assistant turn.
     */
    public static function assistant(
        string $content,
        ?CoETrace $coeTrace = null,
        ?DateTimeImmutable $timestamp = null,
    ): self {
        return new self(
            role: 'assistant',
            content: $content,
            timestamp: $timestamp ?? new DateTimeImmutable(),
            coeTrace: $coeTrace,
        );
    }

    /**
     * Create instance from array.
     *
     * @param array{role: string, content: string, timestamp: string, coe_trace?: array<string, mixed>|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: $data['role'],
            content: $data['content'],
            timestamp: new DateTimeImmutable($data['timestamp']),
            coeTrace: isset($data['coe_trace']) ? CoETrace::fromArray($data['coe_trace']) : null,
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array{role: string, content: string, timestamp: string, coe_trace: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content,
            'timestamp' => $this->timestamp->format('c'),
            'coe_trace' => $this->coeTrace?->toArray(),
        ];
    }

    /**
     * Check if this is a user turn.
     */
    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    /**
     * Check if this is an assistant turn.
     */
    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }
}
