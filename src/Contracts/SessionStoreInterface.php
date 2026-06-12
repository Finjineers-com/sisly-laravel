<?php

declare(strict_types=1);

namespace Sisly\Contracts;

use Sisly\DTOs\Session;

/**
 * Interface for session storage adapters.
 */
interface SessionStoreInterface
{
    /**
     * Retrieve a session by ID.
     */
    public function get(string $sessionId): ?Session;

    /**
     * Save a session.
     */
    public function save(Session $session): void;

    /**
     * Delete a session.
     */
    public function delete(string $sessionId): void;

    /**
     * Check if a session exists.
     */
    public function exists(string $sessionId): bool;
}
