<?php

declare(strict_types=1);

namespace Sisly\Exceptions;

/**
 * Exception thrown when a session is not found.
 */
class SessionNotFoundException extends SislyException
{
    public function __construct(string $sessionId)
    {
        parent::__construct("Session not found: {$sessionId}");
    }
}
