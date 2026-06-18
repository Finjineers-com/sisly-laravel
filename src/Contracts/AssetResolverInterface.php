<?php

declare(strict_types=1);

namespace Sisly\Contracts;

use Sisly\DTOs\Prescription;

/**
 * Interface for resolving a prescription to a real content library asset.
 *
 * Spec: "resolveAsset(prescription, locale) queries the content library and
 * returns the matching 2-minute asset's asset_id and streaming URL."
 *
 * Implementation is application-specific. Register your resolver as:
 *   config('sisly.prescription.asset_resolver') => YourClass::class
 *
 * Match strategy (per spec):
 * - Filter by content_type and locale
 * - Narrow by current_mood → target_mood
 * - Pick highest-rated or least-recently-served asset
 * - NEVER recommend an English asset in an Arabic chat (locale_strict = true)
 * - If no asset matches, return null — coach will try different content_type
 */
interface AssetResolverInterface
{
    /**
     * Resolve a prescription to an asset.
     *
     * @return Prescription|null Prescription with asset_id and asset_url set,
     *                           or null if no matching asset found.
     */
    public function resolve(Prescription $prescription, string $locale): ?Prescription;
}
