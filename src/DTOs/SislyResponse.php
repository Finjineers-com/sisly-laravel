<?php

declare(strict_types=1);

namespace Sisly\DTOs;

use DateTimeImmutable;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * Response from startSession() or message().
 */
final class SislyResponse
{
    public function __construct(
        public readonly string $sessionId,
        public readonly CoachId $coachId,
        public readonly string $coachName,
        public readonly string $responseText,
        public readonly ?string $arabicMirror,
        public readonly SessionState $state,
        public readonly int $turnCount,
        public readonly CrisisInfo $crisis,
        public readonly ?CoETrace $coeTrace,
        public readonly bool $sessionComplete,
        public readonly ?string $handoffSuggested,
        public readonly DateTimeImmutable $timestamp,
    ) {}

    /**
     * Create a response from a session and response text.
     */
    public static function fromSession(
        Session $session,
        string $responseText,
        ?string $arabicMirror = null,
        ?CoETrace $coeTrace = null,
        ?string $handoffSuggested = null,
    ): self {
        return new self(
            sessionId: $session->id,
            coachId: $session->coachId,
            coachName: $session->coachId->displayName(),
            responseText: $responseText,
            arabicMirror: $arabicMirror,
            state: $session->state,
            turnCount: $session->turnCount,
            crisis: $session->crisis,
            coeTrace: $session->preferences->includeCoETrace ? $coeTrace : null,
            sessionComplete: !$session->isActive || $session->state->isTerminal(),
            handoffSuggested: $handoffSuggested,
            timestamp: new DateTimeImmutable(),
        );
    }

    /**
     * Convert to array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'coach_id' => $this->coachId->value,
            'coach_name' => $this->coachName,
            'response_text' => $this->responseText,
            'arabic_mirror' => $this->arabicMirror,
            'state' => $this->state->value,
            'turn_count' => $this->turnCount,
            'crisis' => [
                'detected' => $this->crisis->detected,
                'severity' => $this->crisis->severity?->value,
                'resources_provided' => $this->crisis->resourcesProvided,
            ],
            'coe_trace' => $this->coeTrace?->toArray(),
            'session_complete' => $this->sessionComplete,
            'handoff_suggested' => $this->handoffSuggested,
            'timestamp' => $this->timestamp->format('c'),
        ];
    }

    /**
     * Convert to JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
}
