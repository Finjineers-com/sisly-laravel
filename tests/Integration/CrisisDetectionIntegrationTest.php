<?php

declare(strict_types=1);

namespace Sisly\Tests\Integration;

use Sisly\DTOs\GeoContext;
use Sisly\Enums\CrisisCategory;
use Sisly\Enums\CrisisSeverity;
use Sisly\Enums\SessionState;
use Sisly\Events\CrisisDetected;
use Sisly\Facades\Sisly;
use Sisly\Tests\TestCase;
use Illuminate\Support\Facades\Event;

class CrisisDetectionIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([CrisisDetected::class]);
    }

    // ==================== CRISIS DETECTION ON SESSION START ====================

    public function test_detects_crisis_on_session_start(): void
    {
        $response = Sisly::startSession(
            message: 'I want to kill myself',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        $this->assertTrue($response->crisis->detected);
        $this->assertEquals(CrisisSeverity::CRITICAL, $response->crisis->severity);
        $this->assertEquals(CrisisCategory::SUICIDE, $response->crisis->category);
        $this->assertEquals(SessionState::CRISIS_INTERVENTION, $response->state);
    }

    public function test_crisis_response_includes_emergency_number(): void
    {
        $response = Sisly::startSession(
            message: 'I want to die',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        // UAE emergency number is 999
        $this->assertStringContainsString('999', $response->responseText);
    }

    public function test_crisis_response_includes_hotline(): void
    {
        $response = Sisly::startSession(
            message: 'I want to end my life',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        // UAE has HOPE hotline
        $this->assertStringContainsString('HOPE', $response->responseText);
    }

    public function test_crisis_dispatches_event(): void
    {
        Sisly::startSession(
            message: 'I want to kill myself',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        Event::assertDispatched(CrisisDetected::class, function ($event) {
            return $event->severity === CrisisSeverity::CRITICAL
                && $event->category === CrisisCategory::SUICIDE
                && $event->country === 'AE';
        });
    }

    // ==================== CRISIS DETECTION ON MESSAGE ====================

    public function test_detects_crisis_on_subsequent_message(): void
    {
        // Start normal session
        $startResponse = Sisly::startSession(
            message: 'I am feeling anxious about my presentation',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        $this->assertFalse($startResponse->crisis->detected);

        // Send crisis message
        $response = Sisly::message(
            sessionId: $startResponse->sessionId,
            message: 'I just want to end it all'
        );

        $this->assertTrue($response->crisis->detected);
        $this->assertEquals(SessionState::CRISIS_INTERVENTION, $response->state);
    }

    public function test_session_stays_in_crisis_state(): void
    {
        // Start with crisis message
        $startResponse = Sisly::startSession(
            message: 'I want to kill myself',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        $this->assertEquals(SessionState::CRISIS_INTERVENTION, $startResponse->state);

        // Send follow-up message
        $response = Sisly::message(
            sessionId: $startResponse->sessionId,
            message: 'I feel so alone'
        );

        // Should stay in crisis intervention
        $this->assertEquals(SessionState::CRISIS_INTERVENTION, $response->state);
        $this->assertStringContainsString("I'm", $response->responseText); // Follow-up response
    }

    // ==================== ARABIC CRISIS DETECTION ====================

    public function test_detects_arabic_crisis_keywords(): void
    {
        $response = Sisly::startSession(
            message: 'أريد أن أموت',
            context: ['geo' => new GeoContext(country: 'SA')]
        );

        $this->assertTrue($response->crisis->detected);
        $this->assertEquals(CrisisCategory::SUICIDE, $response->crisis->category);
    }

    public function test_arabic_crisis_response_has_arabic_mirror(): void
    {
        $response = Sisly::startSession(
            message: 'أريد أن أموت',
            context: ['geo' => new GeoContext(country: 'SA')]
        );

        $this->assertNotNull($response->arabicMirror);
        // Arabic mirror should contain Arabic text
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $response->arabicMirror);
    }

    // ==================== COUNTRY-SPECIFIC RESOURCES ====================

    public function test_saudi_crisis_includes_911(): void
    {
        $response = Sisly::startSession(
            message: 'I want to kill myself',
            context: ['geo' => new GeoContext(country: 'SA')]
        );

        $this->assertStringContainsString('911', $response->responseText);
    }

    public function test_kuwait_crisis_includes_112(): void
    {
        $response = Sisly::startSession(
            message: 'I want to kill myself',
            context: ['geo' => new GeoContext(country: 'KW')]
        );

        $this->assertStringContainsString('112', $response->responseText);
    }

    // ==================== NON-CRISIS MESSAGES ====================

    public function test_normal_message_does_not_trigger_crisis(): void
    {
        $response = Sisly::startSession(
            message: 'I am feeling stressed about work',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        $this->assertFalse($response->crisis->detected);
        $this->assertNotEquals(SessionState::CRISIS_INTERVENTION, $response->state);
    }

    public function test_anxiety_message_does_not_trigger_crisis(): void
    {
        $response = Sisly::startSession(
            message: 'I have a big presentation tomorrow and I am very nervous',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        $this->assertFalse($response->crisis->detected);
    }

    // ==================== OTHER CRISIS CATEGORIES ====================

    public function test_detects_self_harm(): void
    {
        $response = Sisly::startSession(
            message: 'I keep cutting myself when I feel bad',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        $this->assertTrue($response->crisis->detected);
        $this->assertEquals(CrisisCategory::SELF_HARM, $response->crisis->category);
        $this->assertEquals(CrisisSeverity::HIGH, $response->crisis->severity);
    }

    public function test_detects_abuse(): void
    {
        $response = Sisly::startSession(
            message: 'My partner is hitting me every day',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        $this->assertTrue($response->crisis->detected);
        $this->assertEquals(CrisisCategory::ABUSE, $response->crisis->category);
    }

    public function test_detects_medical_emergency(): void
    {
        $response = Sisly::startSession(
            message: 'I took an overdose of pills',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        $this->assertTrue($response->crisis->detected);
        $this->assertEquals(CrisisCategory::MEDICAL_EMERGENCY, $response->crisis->category);
    }
}
