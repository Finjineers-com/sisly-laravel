<?php

declare(strict_types=1);

namespace Sisly\DTOs;

use DateTimeImmutable;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * Response from startSession(), initSession(), or message().
 *
 * The response shape aligns to the functional spec contract:
 *
 * Normal turn:
 * { safety: {verdict, category}, coach_text, prescription: null, ended: false }
 *
 * Handoff turn:
 * { safety: {verdict}, coach_text, prescription: {content_type, current_mood,
 *   target_mood, reason, asset_id, asset_url}, ended: false }
 *
 * Crisis:
 * { safety: {verdict: "flagged", category}, coach_text, prescription: null, ended: true }
 */
final class SislyResponse
{
    public function __construct(
        public readonly string $sessionId,
        public readonly CoachId $coachId,
        public readonly string $coachName,
        public readonly string $responseText,     // coach_text in spec
        public readonly ?string $arabicMirror,    // @deprecated since v1.2 — null for coaching; kept for crisis
        public readonly SessionState $state,
        public readonly int $turnCount,
        public readonly CrisisInfo $crisis,
        public readonly ?CoETrace $coeTrace,
        public readonly bool $sessionComplete,    // ended: true/false in spec
        public readonly ?string $handoffSuggested,
        public readonly DateTimeImmutable $timestamp,
        public readonly ?SafetyVerdict $safetyVerdict = null,   // Parallel classifier result
        public readonly ?Prescription $prescription = null,      // Content handoff (null until handoff turn)
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
        ?SafetyVerdict $safetyVerdict = null,
        ?Prescription $prescription = null,
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
            safetyVerdict: $safetyVerdict,
            prescription: $prescription,
        );
    }

    /**
     * Convert to array for JSON serialization.
     * Shape mirrors the functional spec contract.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id'       => $this->sessionId,
            'coach_id'         => $this->coachId->value,
            'coach_name'       => $this->coachName,
            'coach_text'       => $this->responseText,     // spec key
            'response_text'    => $this->responseText,     // backward compat
            'arabic_mirror'    => $this->arabicMirror,     // @deprecated
            'safety'           => $this->safetyVerdict?->toArray() ?? ['verdict' => 'ok', 'category' => 'none'],
            'prescription'     => $this->prescription?->toArray(),
            'ended'            => $this->sessionComplete,  // spec key
            'session_complete' => $this->sessionComplete,  // backward compat
            'state'            => $this->state->value,
            'turn_count'       => $this->turnCount,
            'crisis'           => [
                'detected'           => $this->crisis->detected,
                'severity'           => $this->crisis->severity?->value,
                'resources_provided' => $this->crisis->resourcesProvided,
            ],
            'coe_trace'        => $this->coeTrace?->toArray(),
            'handoff_suggested' => $this->handoffSuggested,
            'timestamp'        => $this->timestamp->format('c'),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
}
