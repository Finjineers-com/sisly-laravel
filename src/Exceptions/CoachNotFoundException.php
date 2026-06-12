<?php

declare(strict_types=1);

namespace Sisly\Exceptions;

/**
 * Exception thrown when a requested coach is not found or disabled.
 */
class CoachNotFoundException extends SislyException
{
    private string $coachId;
    private bool $isDisabled;

    public function __construct(string $coachId, bool $isDisabled = false)
    {
        $this->coachId = $coachId;
        $this->isDisabled = $isDisabled;

        $message = $isDisabled
            ? "Coach '{$coachId}' is disabled"
            : "Coach '{$coachId}' not found";

        parent::__construct($message);
    }

    public function getCoachId(): string
    {
        return $this->coachId;
    }

    public function isDisabled(): bool
    {
        return $this->isDisabled;
    }
}
