<?php

declare(strict_types=1);

namespace Sisly\Prescription;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Sisly\DTOs\Prescription;
use Sisly\DTOs\ResolvedPrescription;
use Sisly\DTOs\Session;

class PrescriptionResolver
{
    /**
     * Fetch and cache the content pool for a session.
     *
     * @return array<array<string, mixed>>
     */
    public function getContentPool(Session $session, string $contentType): array
    {
        $cacheKey = "sisly:content_pool:{$session->id}";
        
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey) ?: [];
        }
        
        try {
            $path = $this->getConfig('sisly.prescription.api_url', 'https://api.sisly.ai/api/v1/insights/by-type');
            $url = $this->getApiUrl($path);
            $locale = $session->preferences->language;

            $response = Http::timeout(5)
                ->get($url, [
                    'content_type' => $contentType,
                    'local' => $locale,
                ]);

            if ($response->successful()) {
                $pool = $response->json();
                if (is_array($pool)) {
                    $ttl = $this->getConfig('sisly.prescription.cache_ttl', 1800);
                    Cache::put($cacheKey, $pool, $ttl);
                    return $pool;
                }
            }
        } catch (\Throwable $e) {
            if (function_exists('app') && app()->bound('log')) {
                app('log')->error('Sisly content library API call failed', [
                    'error' => $e->getMessage(),
                    'session_id' => $session->id,
                    'content_type' => $contentType,
                ]);
            }
        }

        return [];
    }

    /**
     * Resolve a Prescription into a ResolvedPrescription.
     */
    public function resolve(Session $session, Prescription $prescription): ?ResolvedPrescription
    {
        $contentTypeVal = $prescription->contentType->value;
        $pool = $this->getContentPool($session, $contentTypeVal);

        if (empty($pool)) {
            return null;
        }

        // Retrieve served content IDs for this session
        $servedCacheKey = "sisly:served_content:{$session->id}";
        $servedIds = Cache::get($servedCacheKey, []);
        if (!is_array($servedIds)) {
            $servedIds = [];
        }

        // Filter unserved items
        $unserved = array_filter($pool, function ($item) use ($servedIds) {
            return isset($item['content_id']) && !in_array($item['content_id'], $servedIds, true);
        });

        // Reset logic: If available is empty -> clear served cache -> all items available again
        if (empty($unserved)) {
            $servedIds = [];
            Cache::forget($servedCacheKey);
            $unserved = $pool;
        }

        if (empty($unserved)) {
            return null;
        }

        // Pick one item randomly (using array_values to ensure indices are normalized after filtering)
        $unservedValues = array_values($unserved);
        $selected = $unservedValues[array_rand($unservedValues)];

        // Mark as served
        $servedIds[] = $selected['content_id'];
        $ttl = $this->getConfig('sisly.prescription.cache_ttl', 1800);
        Cache::put($servedCacheKey, $servedIds, $ttl);

        $media = $selected['media'] ?? [];

        return new ResolvedPrescription(
            contentId: (int) ($selected['content_id'] ?? 0),
            title: (string) ($selected['title'] ?? ''),
            description: (string) ($selected['description'] ?? ''),
            duration: (int) ($selected['duration'] ?? 0),
            mediaCategory: (string) ($selected['media_category'] ?? ''),
            audioPath: (string) ($media['audio_path'] ?? ''),
            audioThumbnail: (string) ($media['audio_thumbnail'] ?? ''),
            reason: $prescription->reason
        );
    }

    /**
     * Clear content cache for a session (useful on cleanup).
     */
    public function clearSessionCache(string $sessionId): void
    {
        Cache::forget("sisly:content_pool:{$sessionId}");
        Cache::forget("sisly:served_content:{$sessionId}");
    }

    /**
     * Resolve path/URL to fully qualified API endpoint.
     */
    protected function getApiUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        
        if (function_exists('url')) {
            return url($path);
        }
        
        $appUrl = $this->getConfig('app.url', 'http://localhost');
        return rtrim($appUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Get a configuration value safely without throwing container errors in unit tests.
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        if (function_exists('app') && app()->bound('config')) {
            return config($key, $default);
        }
        return $default;
    }
}
