<?php

declare(strict_types=1);

namespace Sisly\Resolvers;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Sisly\Contracts\CoachAwareAssetResolverInterface;
use Sisly\ContentLibrary\CoachLibraryMap;
use Sisly\ContentLibrary\SislyContentLibraryClient;
use Sisly\DTOs\Prescription;

final class CoachMappedAssetResolver implements CoachAwareAssetResolverInterface
{
    private SislyContentLibraryClient $client;

    public function __construct(?ClientInterface $httpClient = null)
    {
        $this->client = new SislyContentLibraryClient(
            $httpClient ?? new Client(),
        );
    }

    public function resolve(Prescription $prescription, string $locale): ?Prescription
    {
        $coachId = (string) config('sisly.content_library.current_coach_id')
            ?: (string) config('sisly.content_library.default_coach_id', config('sisly.coaches.default', 'meetly'));

        return $this->resolveForCoach($coachId, $prescription, $locale);
    }

    public function resolveForCoach(string $coachId, Prescription $prescription, string $locale): ?Prescription
    {
        $libraryType = CoachLibraryMap::libraryTypeForCoach($coachId);
        $items = $this->client->fetchByLibraryType($libraryType, $locale);

        if ($items === []) {
            return null;
        }

        $selected = $this->pickBestItem($items, $prescription);
        if (!is_array($selected)) {
            return null;
        }

        $assetUrl = $selected['media']['audio_path'] ?? $selected['media']['audio_url'] ?? null;
        $assetId = isset($selected['content_id']) ? (string) $selected['content_id'] : null;

        if (!is_string($assetUrl) || $assetUrl === '') {
            return null;
        }

        return new Prescription(
            contentType: $prescription->contentType,
            currentMood: $prescription->currentMood,
            targetMood: $prescription->targetMood,
            reason: $prescription->reason,
            assetId: $assetId,
            assetUrl: $assetUrl,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>|null
     */
    private function pickBestItem(array $items, Prescription $prescription): ?array
    {
        $expectedCategory = $this->normalizeMediaCategory($prescription->contentType);

        foreach ($items as $item) {
            $category = $this->normalizeMediaCategory((string) ($item['media_category'] ?? ''));
            if ($category === $expectedCategory) {
                return $item;
            }
        }

        return $items[0] ?? null;
    }

    private function normalizeMediaCategory(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}
