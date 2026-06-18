<?php

declare(strict_types=1);

namespace Sisly\ContentLibrary;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class SislyContentLibraryClient
{
    public function __construct(
        private readonly ClientInterface $httpClient,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchByLibraryType(string $libraryType, string $locale): array
    {
        $endpoint = rtrim((string) config('sisly.content_library.endpoint', 'https://api.sisly.ai/api/v1/insights/by-type'), '/');
        $local = str_starts_with(strtolower($locale), 'ar') ? 'arabic' : 'english';

        try {
            $response = $this->httpClient->request('GET', $endpoint, [
                'query' => [
                    'content_type' => $libraryType,
                    'local' => $local,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
                'timeout' => (float) config('sisly.content_library.timeout', 15),
            ]);

            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException|\JsonException) {
            return [];
        }

        if (is_array($payload) && array_is_list($payload)) {
            return $payload;
        }

        if (is_array($payload) && isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return [];
    }
}
