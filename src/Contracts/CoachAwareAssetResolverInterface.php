<?php

declare(strict_types=1);

namespace Sisly\Contracts;

use Sisly\DTOs\Prescription;

interface CoachAwareAssetResolverInterface extends AssetResolverInterface
{
    public function resolveForCoach(string $coachId, Prescription $prescription, string $locale): ?Prescription;
}
