<?php

declare(strict_types=1);

namespace Sisly\Enums;

enum SessionState: string
{
    case INTAKE = 'intake';
    case RISK_TRIAGE = 'risk_triage';
    case EXPLORATION = 'exploration';
    case DEEPENING = 'deepening';
    case PROBLEM_SOLVING = 'problem_solving';
    case CLOSING = 'closing';
    case CRISIS_INTERVENTION = 'crisis_intervention'; // Trap state - no exit

    /**
     * Check if this is a terminal state (session should end).
     */
    public function isTerminal(): bool
    {
        return $this === self::CLOSING;
    }

    /**
     * Check if this is the crisis trap state.
     */
    public function isCrisis(): bool
    {
        return $this === self::CRISIS_INTERVENTION;
    }

    /**
     * Get human-readable label for the state.
     */
    public function label(): string
    {
        return match ($this) {
            self::INTAKE => 'Intake',
            self::RISK_TRIAGE => 'Risk Triage',
            self::EXPLORATION => 'Exploration',
            self::DEEPENING => 'Deepening',
            self::PROBLEM_SOLVING => 'Problem Solving',
            self::CLOSING => 'Closing',
            self::CRISIS_INTERVENTION => 'Crisis Intervention',
        };
    }
}
