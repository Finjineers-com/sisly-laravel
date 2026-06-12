<?php

declare(strict_types=1);

namespace Sisly\Session\Adapters;

use Illuminate\Support\Facades\Cache;
use Sisly\Contracts\SessionStoreInterface;
use Sisly\DTOs\Session;

/**
 * Session storage adapter using Laravel's cache system.
 */
class LaravelCacheAdapter implements SessionStoreInterface
{
    private string $prefix;
    private int $ttl;

    /**
     * @param array{prefix?: string, ttl?: int} $config
     */
    public function __construct(array $config = [])
    {
        $this->prefix = $config['prefix'] ?? 'sisly:session:';
        $this->ttl = $config['ttl'] ?? 1800; // 30 minutes
    }

    /**
     * Retrieve a session by ID.
     */
    public function get(string $sessionId): ?Session
    {
        $data = Cache::get($this->key($sessionId));

        if ($data === null) {
            return null;
        }

        return Session::fromArray($data);
    }

    /**
     * Save a session.
     */
    public function save(Session $session): void
    {
        Cache::put(
            $this->key($session->id),
            $session->toArray(),
            $this->ttl
        );
    }

    /**
     * Delete a session.
     */
    public function delete(string $sessionId): void
    {
        Cache::forget($this->key($sessionId));
    }

    /**
     * Check if a session exists.
     */
    public function exists(string $sessionId): bool
    {
        return Cache::has($this->key($sessionId));
    }

    /**
     * Generate the cache key for a session.
     */
    private function key(string $sessionId): string
    {
        return $this->prefix . $sessionId;
    }
}
