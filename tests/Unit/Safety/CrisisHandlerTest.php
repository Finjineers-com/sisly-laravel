<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Safety;

use PHPUnit\Framework\TestCase;
use Sisly\DTOs\CrisisInfo;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;
use Sisly\Enums\CrisisCategory;
use Sisly\Enums\CrisisSeverity;
use Sisly\Enums\SessionState;
use Sisly\Safety\CrisisHandler;
use Sisly\Safety\CrisisResourceProvider;

class CrisisHandlerTest extends TestCase
{
    private CrisisHandler $handler;
    private CrisisResourceProvider $resourceProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resourceProvider = new CrisisResourceProvider();
        $this->handler = new CrisisHandler($this->resourceProvider);
    }

    // ==================== CRISIS RESPONSE GENERATION ====================

    public function test_handle_returns_sisly_response(): void
    {
        $crisis = CrisisInfo::detected(
            severity: CrisisSeverity::CRITICAL,
            category: CrisisCategory::SUICIDE,
            keywords: ['kill myself'],
        );
        $geo = new GeoContext(country: 'AE');
        $session = $this->createSession($geo);

        $response = $this->handler->handle($crisis, $geo, $session);

        $this->assertEquals($session->id, $response->sessionId);
        $this->assertEquals(SessionState::CRISIS_INTERVENTION, $response->state);
        $this->assertTrue($response->crisis->resourcesProvided);
    }

    public function test_critical_severity_response_includes_safety_message(): void
    {
        $crisis = CrisisInfo::detected(
            severity: CrisisSeverity::CRITICAL,
            category: CrisisCategory::SUICIDE,
        );
        $geo = new GeoContext(country: 'AE');
        $session = $this->createSession($geo);

        $response = $this->handler->handle($crisis, $geo, $session);

        $this->assertStringContainsString('safety', strtolower($response->responseText));
    }

    public function test_response_includes_hotline_when_available(): void
    {
        $crisis = CrisisInfo::detected(
            severity: CrisisSeverity::HIGH,
            category: CrisisCategory::SELF_HARM,
        );
        $geo = new GeoContext(country: 'AE');
        $session = $this->createSession($geo);

        $response = $this->handler->handle($crisis, $geo, $session);

        // UAE has HOPE line
        $this->assertStringContainsString('HOPE', $response->responseText);
    }

    public function test_response_includes_emergency_number(): void
    {
        $crisis = CrisisInfo::detected(
            severity: CrisisSeverity::CRITICAL,
            category: CrisisCategory::SUICIDE,
        );
        $geo = new GeoContext(country: 'AE');
        $session = $this->createSession($geo);

        $response = $this->handler->handle($crisis, $geo, $session);

        $this->assertStringContainsString('999', $response->responseText);
    }

    public function test_arabic_mirror_is_included_when_enabled(): void
    {
        $crisis = CrisisInfo::detected(
            severity: CrisisSeverity::CRITICAL,
            category: CrisisCategory::SUICIDE,
        );
        $geo = new GeoContext(country: 'AE');
        $session = $this->createSession($geo, arabicMirror: true);

        $response = $this->handler->handle($crisis, $geo, $session);

        $this->assertNotNull($response->arabicMirror);
        $this->assertStringContainsString('999', $response->arabicMirror);
    }

    public function test_arabic_mirror_is_null_when_disabled(): void
    {
        $crisis = CrisisInfo::detected(
            severity: CrisisSeverity::CRITICAL,
            category: CrisisCategory::SUICIDE,
        );
        $geo = new GeoContext(country: 'AE');
        $session = $this->createSession($geo, arabicMirror: false);

        $response = $this->handler->handle($crisis, $geo, $session);

        $this->assertNull($response->arabicMirror);
    }

    // ==================== COUNTRY-SPECIFIC RESOURCES ====================

    public function test_saudi_response_includes_911(): void
    {
        $crisis = CrisisInfo::detected(
            severity: CrisisSeverity::CRITICAL,
            category: CrisisCategory::SUICIDE,
        );
        $geo = new GeoContext(country: 'SA');
        $session = $this->createSession($geo);

        $response = $this->handler->handle($crisis, $geo, $session);

        $this->assertStringContainsString('911', $response->responseText);
    }

    public function test_kuwait_response_includes_112(): void
    {
        $crisis = CrisisInfo::detected(
            severity: CrisisSeverity::CRITICAL,
            category: CrisisCategory::SUICIDE,
        );
        $geo = new GeoContext(country: 'KW');
        $session = $this->createSession($geo);

        $response = $this->handler->handle($crisis, $geo, $session);

        $this->assertStringContainsString('112', $response->responseText);
    }

    // ==================== ARABIC LANGUAGE ====================

    public function test_arabic_language_preference_returns_arabic_response(): void
    {
        $crisis = CrisisInfo::detected(
            severity: CrisisSeverity::CRITICAL,
            category: CrisisCategory::SUICIDE,
        );
        $geo = new GeoContext(country: 'AE');
        $session = $this->createSession($geo, language: 'ar');

        $response = $this->handler->handle($crisis, $geo, $session);

        // Should contain Arabic text
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $response->responseText);
    }

    // ==================== FOLLOW-UP RESPONSE ====================

    public function test_get_follow_up_response_in_english(): void
    {
        $geo = new GeoContext(country: 'AE');
        $session = $this->createSession($geo);

        $response = $this->handler->getFollowUpResponse($session, $geo);

        $this->assertStringContainsString("I'm still here", $response);
    }

    public function test_get_follow_up_response_in_arabic(): void
    {
        $geo = new GeoContext(country: 'AE');
        $session = $this->createSession($geo, language: 'ar');

        $response = $this->handler->getFollowUpResponse($session, $geo);

        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $response);
    }

    // ==================== SPECIAL HANDLING ====================

    public function test_requires_special_handling_for_critical_categories(): void
    {
        $this->assertTrue($this->handler->requiresSpecialHandling(CrisisCategory::SUICIDE));
        $this->assertTrue($this->handler->requiresSpecialHandling(CrisisCategory::HARM_TO_OTHERS));
        $this->assertTrue($this->handler->requiresSpecialHandling(CrisisCategory::MEDICAL_EMERGENCY));
        $this->assertTrue($this->handler->requiresSpecialHandling(CrisisCategory::ABUSE));
    }

    public function test_does_not_require_special_handling_for_other_categories(): void
    {
        $this->assertFalse($this->handler->requiresSpecialHandling(CrisisCategory::SELF_HARM));
        $this->assertFalse($this->handler->requiresSpecialHandling(CrisisCategory::PSYCHOSIS));
    }

    // ==================== CATEGORY GUIDANCE ====================

    public function test_get_abuse_guidance_in_english(): void
    {
        $guidance = $this->handler->getCategoryGuidance(CrisisCategory::ABUSE, 'en');

        $this->assertNotNull($guidance);
        $this->assertStringContainsString('safety', strtolower($guidance));
    }

    public function test_get_medical_emergency_guidance(): void
    {
        $guidance = $this->handler->getCategoryGuidance(CrisisCategory::MEDICAL_EMERGENCY, 'en');

        $this->assertNotNull($guidance);
        $this->assertStringContainsString('emergency', strtolower($guidance));
    }

    public function test_get_guidance_returns_null_for_no_specific_guidance(): void
    {
        $guidance = $this->handler->getCategoryGuidance(CrisisCategory::SELF_HARM, 'en');

        $this->assertNull($guidance);
    }

    // ==================== HELPER METHODS ====================

    private function createSession(
        GeoContext $geo,
        string $language = 'en',
        bool $arabicMirror = true,
    ): Session {
        return Session::create(
            id: 'test-session-' . uniqid(),
            coachId: CoachId::MEETLY,
            geo: $geo,
            preferences: new SessionPreferences(
                language: $language,
                arabicMirror: $arabicMirror,
            ),
        );
    }
}
