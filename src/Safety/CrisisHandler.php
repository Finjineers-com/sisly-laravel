<?php

declare(strict_types=1);

namespace Sisly\Safety;

use DateTimeImmutable;
use Sisly\DTOs\CrisisInfo;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SislyResponse;
use Sisly\Enums\CoachId;
use Sisly\Enums\CrisisCategory;
use Sisly\Enums\CrisisSeverity;
use Sisly\Enums\SessionState;

/**
 * Handles crisis situations by generating appropriate responses with resources.
 *
 * This class generates hard-coded, deterministic crisis responses.
 * No LLM is involved in crisis response generation for safety.
 */
class CrisisHandler
{
    public function __construct(
        private readonly CrisisResourceProvider $resourceProvider,
    ) {}

    /**
     * Handle a crisis situation and generate a response.
     */
    public function handle(
        CrisisInfo $crisis,
        GeoContext $geo,
        Session $session,
    ): SislyResponse {
        $resources = $this->resourceProvider->getForGeoContext($geo);
        $language = $session->preferences->language;

        $responseText = $this->buildCrisisResponse($crisis, $resources, $language);
        $arabicMirror = $this->buildArabicResponse($crisis, $resources);

        // Update crisis info to mark resources as provided
        $updatedCrisis = $crisis->withResourcesProvided();

        return new SislyResponse(
            sessionId: $session->id,
            coachId: $session->coachId,
            coachName: 'Crisis Support',
            responseText: $responseText,
            arabicMirror: $session->preferences->arabicMirror ? $arabicMirror : null,
            state: SessionState::CRISIS_INTERVENTION,
            turnCount: $session->turnCount,
            crisis: $updatedCrisis,
            coeTrace: null, // No CoE trace in crisis mode
            sessionComplete: false, // Session continues in crisis mode
            handoffSuggested: null,
            timestamp: new DateTimeImmutable(),
        );
    }

    /**
     * Build the crisis response text.
     *
     * @param array<string, mixed> $resources
     */
    private function buildCrisisResponse(
        CrisisInfo $crisis,
        array $resources,
        string $language,
    ): string {
        $parts = [];

        // Opening acknowledgment based on severity
        $parts[] = $this->getOpeningAcknowledgment($crisis->severity, $language);

        // Add hotline if available
        $hotline = $resources['hotline'] ?? null;
        if ($hotline !== null) {
            $hotlineName = $language === 'ar' ? ($hotline['name_ar'] ?: $hotline['name']) : $hotline['name'];
            $parts[] = $language === 'ar'
                ? "يرجى التواصل مع {$hotlineName} على الرقم {$hotline['phone']}."
                : "Please reach out to {$hotlineName} at {$hotline['phone']}.";
        }

        // Add emergency number
        $emergency = $resources['emergency_number'] ?? '911';
        $parts[] = $language === 'ar'
            ? "إذا كنت في خطر فوري، يرجى الاتصال بالطوارئ على {$emergency}."
            : "If you're in immediate danger, please contact emergency services at {$emergency}.";

        // Closing
        $parts[] = $language === 'ar'
            ? "أنا هنا معك."
            : "I'm here with you.";

        return implode(' ', $parts);
    }

    /**
     * Build the Arabic mirror response.
     *
     * @param array<string, mixed> $resources
     */
    private function buildArabicResponse(CrisisInfo $crisis, array $resources): string
    {
        $parts = [];

        // Opening acknowledgment
        $parts[] = $this->getOpeningAcknowledgment($crisis->severity, 'ar');

        // Add hotline if available
        $hotline = $resources['hotline'] ?? null;
        if ($hotline !== null) {
            $hotlineName = $hotline['name_ar'] ?: $hotline['name'];
            $parts[] = "يرجى التواصل مع {$hotlineName} على الرقم {$hotline['phone']}.";
        }

        // Add emergency number
        $emergency = $resources['emergency_number'] ?? '911';
        $parts[] = "إذا كنت في خطر فوري، يرجى الاتصال بالطوارئ على {$emergency}.";

        // Closing
        $parts[] = "أنا هنا معك.";

        return implode(' ', $parts);
    }

    /**
     * Get the opening acknowledgment based on severity.
     */
    private function getOpeningAcknowledgment(?CrisisSeverity $severity, string $language): string
    {
        $isCritical = $severity === CrisisSeverity::CRITICAL;

        if ($language === 'ar') {
            return $isCritical
                ? 'أسمعك وأفهم أنك تمر بوقت صعب جداً الآن. سلامتك مهمة جداً.'
                : 'ما تمر به يبدو صعباً. أهتم بسلامتك.';
        }

        return $isCritical
            ? "I hear that you're going through something really difficult right now. Your safety matters deeply."
            : "What you're describing sounds really hard. I care about your wellbeing.";
    }

    /**
     * Get a follow-up response for continued crisis support.
     */
    public function getFollowUpResponse(Session $session, GeoContext $geo): string
    {
        $resources = $this->resourceProvider->getForGeoContext($geo);
        $language = $session->preferences->language;

        $hotline = $resources['hotline'] ?? null;
        $emergency = $resources['emergency_number'] ?? '911';

        if ($language === 'ar') {
            $response = "أنا هنا معك. ";
            if ($hotline !== null) {
                $response .= "تذكر أنه يمكنك دائماً التواصل مع {$hotline['name_ar']} على {$hotline['phone']}. ";
            }
            $response .= "للطوارئ، اتصل بـ {$emergency}.";
            return $response;
        }

        $response = "I'm still here with you. ";
        if ($hotline !== null) {
            $response .= "Remember, you can always reach out to {$hotline['name']} at {$hotline['phone']}. ";
        }
        $response .= "For emergencies, call {$emergency}.";

        return $response;
    }

    /**
     * Check if a category requires special handling.
     */
    public function requiresSpecialHandling(CrisisCategory $category): bool
    {
        return match ($category) {
            CrisisCategory::SUICIDE,
            CrisisCategory::HARM_TO_OTHERS,
            CrisisCategory::MEDICAL_EMERGENCY,
            CrisisCategory::ABUSE => true,
            default => false,
        };
    }

    /**
     * Get category-specific additional guidance.
     */
    public function getCategoryGuidance(CrisisCategory $category, string $language): ?string
    {
        $guidance = match ($category) {
            CrisisCategory::ABUSE => [
                'en' => "If you're in an unsafe situation, please know that you deserve safety and support.",
                'ar' => "إذا كنت في وضع غير آمن، تذكر أنك تستحق الأمان والدعم.",
            ],
            CrisisCategory::MEDICAL_EMERGENCY => [
                'en' => "This sounds like a medical emergency. Please call emergency services immediately.",
                'ar' => "هذا يبدو كحالة طوارئ طبية. يرجى الاتصال بالإسعاف فوراً.",
            ],
            default => null,
        };

        if ($guidance === null) {
            return null;
        }

        return $guidance[$language] ?? $guidance['en'];
    }
}
