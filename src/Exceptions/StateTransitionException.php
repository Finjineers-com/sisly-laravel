<?php

declare(strict_types=1);

namespace Sisly\Exceptions;

use Sisly\Enums\SessionState;

/**
 * Exception thrown when an invalid state transition is attempted.
 */
class StateTransitionException extends SislyException
{
    private SessionState $fromState;
    private SessionState $toState;

    public function __construct(SessionState $from, SessionState $to)
    {
        $this->fromState = $from;
        $this->toState = $to;

        parent::__construct(
            "Invalid state transition from '{$from->value}' to '{$to->value}'"
        );
    }

    public function getFromState(): SessionState
    {
        return $this->fromState;
    }

    public function getToState(): SessionState
    {
        return $this->toState;
    }
}
