<?php

declare(strict_types=1);

namespace Sisly\Tests\Integration;

use Sisly\DTOs\GeoContext;
use Sisly\DTOs\SessionPreferences;
use Sisly\DTOs\SislyResponse;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\Exceptions\SessionNotFoundException;
use Sisly\Facades\Sisly;
use Sisly\Tests\TestCase;

class SessionFlowTest extends TestCase
{
    public function test_can_start_session(): void
    {
        $response = Sisly::startSession(
            message: 'I have a big presentation tomorrow and I am nervous',
            context: [
                'geo' => new GeoContext(country: 'AE'),
            ]
        );

        $this->assertInstanceOf(SislyResponse::class, $response);
        $this->assertNotEmpty($response->sessionId);
        $this->assertEquals(CoachId::MEETLY, $response->coachId);
        $this->assertEquals('Meetly', $response->coachName);
        $this->assertNotEmpty($response->responseText);
        $this->assertEquals(SessionState::EXPLORATION, $response->state);
        $this->assertEquals(2, $response->turnCount); // User + Assistant
        $this->assertFalse($response->crisis->detected);
        $this->assertFalse($response->sessionComplete);
    }

    public function test_can_continue_session_with_message(): void
    {
        // Start session
        $startResponse = Sisly::startSession(
            message: 'I have a presentation tomorrow',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        // Send follow-up message
        $response = Sisly::message(
            sessionId: $startResponse->sessionId,
            message: 'What if I forget everything?'
        );

        $this->assertInstanceOf(SislyResponse::class, $response);
        $this->assertEquals($startResponse->sessionId, $response->sessionId);
        $this->assertEquals(4, $response->turnCount); // 2 from start + 2 from message
        $this->assertNotEmpty($response->responseText);
    }

    public function test_can_get_session_state(): void
    {
        $startResponse = Sisly::startSession(
            message: 'I am anxious',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        $state = Sisly::getState($startResponse->sessionId);

        $this->assertEquals('exploration', $state['state']);
        $this->assertEquals(2, $state['turn_count']);
        $this->assertTrue($state['is_active']);
        $this->assertEquals('meetly', $state['coach_id']);
    }

    public function test_can_end_session(): void
    {
        $startResponse = Sisly::startSession(
            message: 'I need help',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        Sisly::endSession($startResponse->sessionId);

        $session = Sisly::getSession($startResponse->sessionId);
        $this->assertFalse($session->isActive);
    }

    public function test_session_exists_returns_true_for_existing_session(): void
    {
        $startResponse = Sisly::startSession(
            message: 'Hello',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        $this->assertTrue(Sisly::sessionExists($startResponse->sessionId));
    }

    public function test_session_exists_returns_false_for_non_existing_session(): void
    {
        $this->assertFalse(Sisly::sessionExists('non-existent-id'));
    }

    public function test_message_throws_exception_for_non_existing_session(): void
    {
        $this->expectException(SessionNotFoundException::class);

        Sisly::message('non-existent-id', 'Hello');
    }

    public function test_can_start_session_with_vento_coach(): void
    {
        $response = Sisly::startSession(
            message: 'I am so angry right now',
            context: [
                'geo' => new GeoContext(country: 'AE'),
                'coach_id' => 'vento',
            ]
        );

        $this->assertEquals(CoachId::VENTO, $response->coachId);
        $this->assertNotEmpty($response->responseText);
    }

    public function test_can_start_session_with_loopy_coach(): void
    {
        $response = Sisly::startSession(
            message: "I can't stop overthinking about what happened",
            context: [
                'geo' => new GeoContext(country: 'AE'),
                'coach_id' => 'loopy',
            ]
        );

        $this->assertEquals(CoachId::LOOPY, $response->coachId);
        $this->assertNotEmpty($response->responseText);
    }

    public function test_can_start_session_with_presso_coach(): void
    {
        $response = Sisly::startSession(
            message: 'I am completely overwhelmed with work',
            context: [
                'geo' => new GeoContext(country: 'AE'),
                'coach_id' => 'presso',
            ]
        );

        $this->assertEquals(CoachId::PRESSO, $response->coachId);
        $this->assertNotEmpty($response->responseText);
    }

    public function test_can_start_session_with_boostly_coach(): void
    {
        $response = Sisly::startSession(
            message: "I feel like I'm not good enough for this role",
            context: [
                'geo' => new GeoContext(country: 'AE'),
                'coach_id' => 'boostly',
            ]
        );

        $this->assertEquals(CoachId::BOOSTLY, $response->coachId);
        $this->assertNotEmpty($response->responseText);
    }

    public function test_can_start_session_with_safeo_coach(): void
    {
        $response = Sisly::startSession(
            message: "I don't know what's going to happen with the layoffs and I can't sleep",
            context: [
                'geo' => new GeoContext(country: 'AE'),
                'coach_id' => 'safeo',
            ]
        );

        $this->assertEquals(CoachId::SAFEO, $response->coachId);
        $this->assertNotEmpty($response->responseText);
    }

    public function test_can_start_session_with_preferences(): void
    {
        $response = Sisly::startSession(
            message: 'Help me',
            context: [
                'geo' => new GeoContext(country: 'SA'),
                'preferences' => new SessionPreferences(
                    language: 'ar',
                    arabicMirror: true,
                    includeCoETrace: true,
                ),
            ]
        );

        $session = Sisly::getSession($response->sessionId);
        $this->assertEquals('ar', $session->preferences->language);
        $this->assertTrue($session->preferences->arabicMirror);
    }

    public function test_response_converts_to_array(): void
    {
        $response = Sisly::startSession(
            message: 'Test',
            context: ['geo' => new GeoContext(country: 'AE')]
        );

        $array = $response->toArray();

        $this->assertArrayHasKey('session_id', $array);
        $this->assertArrayHasKey('coach_id', $array);
        $this->assertArrayHasKey('coach_name', $array);
        $this->assertArrayHasKey('response_text', $array);
        $this->assertArrayHasKey('state', $array);
        $this->assertArrayHasKey('turn_count', $array);
        $this->assertArrayHasKey('crisis', $array);
        $this->assertArrayHasKey('session_complete', $array);
        $this->assertArrayHasKey('timestamp', $array);
    }
}
