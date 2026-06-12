<?php

declare(strict_types=1);

namespace Sisly\Session\Adapters;

use Illuminate\Support\Facades\Redis;
use Sisly\Contracts\SessionStoreInterface;
use Sisly\DTOs\Session;

/**
 * Session storage adapter using Redis directly.
 */
class RedisAdapter implements SessionStoreInterface
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
        $data = Redis::get($this->key($sessionId));

        if ($data === null) {
            return null;
        }

        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return null;
        }

        return Session::fromArray($decoded);
    }

    /**
     * Save a session.
     */
    public function save(Session $session): void
    {
        Redis::setex(
            $this->key($session->id),
            $this->ttl,
            json_encode($session->toArray(), JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Delete a session.
     */
    public function delete(string $sessionId): void
    {
        Redis::del($this->key($sessionId));
    }

    /**
     * Check if a session exists.
     */
    public function exists(string $sessionId): bool
    {
        return (bool) Redis::exists($this->key($sessionId));
    }

    /**
     * Generate the Redis key for a session.
     */
    private function key(string $sessionId): string
    {
        return $this->prefix . $sessionId;
    }
}
