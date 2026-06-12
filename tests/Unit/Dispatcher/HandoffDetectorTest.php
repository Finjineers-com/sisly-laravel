<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Sisly\Dispatcher\HandoffDetector;
use Sisly\DTOs\ConversationTurn;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;

class HandoffDetectorTest extends TestCase
{
    private HandoffDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new HandoffDetector();
    }

    // ==================== HANDOFF DETECTION ====================

    public function test_detects_handoff_to_vento_from_meetly(): void
    {
        $session = $this->createSession(CoachId::MEETLY);

        $result = $this->detector->analyze(
            'I am so angry and frustrated with everything right now',
            $session
        );

        $this->assertTrue($result->suggested);
        $this->assertEquals(CoachId::VENTO, $result->suggestedCoach);
    }

    public function test_detects_handoff_to_presso_from_meetly(): void
    {
        $session = $this->createSession(CoachId::MEETLY);

        $result = $this->detector->analyze(
            'I have too much to do and the pressure is overwhelming',
            $session
        );

        $this->assertTrue($result->suggested);
        $this->assertEquals(CoachId::PRESSO, $result->suggestedCoach);
    }

    public function test_detects_handoff_to_loopy_from_vento(): void
    {
        $session = $this->createSession(CoachId::VENTO);

        $result = $this->detector->analyze(
            'I keep thinking about it in a loop and I am stuck',
            $session
        );

        $this->assertTrue($result->suggested);
        $this->assertEquals(CoachId::LOOPY, $result->suggestedCoach);
    }

    public function test_detects_handoff_to_boostly(): void
    {
        $session = $this->createSession(CoachId::MEETLY);

        $result = $this->detector->analyze(
            'I feel like such an imposter and doubt my capabilities',
            $session
        );

        $this->assertTrue($result->suggested);
        $this->assertEquals(CoachId::BOOSTLY, $result->suggestedCoach);
    }

    // ==================== NO HANDOFF ====================

    public function test_no_handoff_for_single_trigger(): void
    {
        $session = $this->createSession(CoachId::MEETLY);

        // Only one trigger word
        $result = $this->detector->analyze('I feel angry', $session);

        $this->assertFalse($result->suggested);
    }

    public function test_no_handoff_for_same_coach_triggers(): void
    {
        $session = $this->createSession(CoachId::MEETLY);

        // Triggers for current coach should not suggest handoff
        $result = $this->detector->analyze(
            'I have a meeting and presentation tomorrow',
            $session
        );

        $this->assertFalse($result->suggested);
    }

    public function test_no_handoff_for_generic_message(): void
    {
        $session = $this->createSession(CoachId::MEETLY);

        $result = $this->detector->analyze('I am feeling a bit down today', $session);

        $this->assertFalse($result->suggested);
    }

    // ==================== CONFIDENCE ====================

    public function test_confidence_increases_with_more_triggers(): void
    {
        $session = $this->createSession(CoachId::MEETLY);

        // Two triggers
        $result1 = $this->detector->analyze(
            'I am angry and frustrated',
            $session
        );

        // Three triggers
        $result2 = $this->detector->analyze(
            'I am angry, frustrated, and furious',
            $session
        );

        $this->assertGreaterThan($result1->confidence, $result2->confidence);
    }

    public function test_meets_threshold(): void
    {
        $session = $this->createSession(CoachId::MEETLY);

        $result = $this->detector->analyze(
            'I am overwhelmed with too much pressure and deadline stress',
            $session
        );

        $this->assertTrue($result->meetsThreshold(0.5));
    }

    // ==================== TRIGGERS LIST ====================

    public function test_result_includes_matched_triggers(): void
    {
        $session = $this->createSession(CoachId::MEETLY);

        $result = $this->detector->analyze(
            'I am angry and frustrated',
            $session
        );

        $this->assertContains('angry', $result->triggers);
        $this->assertContains('frustrated', $result->triggers);
    }

    // ==================== CUSTOM TRIGGERS ====================

    public function test_add_custom_triggers(): void
    {
        $session = $this->createSession(CoachId::MEETLY);

        $this->detector->addTriggers(CoachId::VENTO, ['custom1', 'custom2']);

        $result = $this->detector->analyze(
            'I am feeling custom1 and custom2',
            $session
        );

        $this->assertTrue($result->suggested);
        $this->assertEquals(CoachId::VENTO, $result->suggestedCoach);
    }

    // ==================== TOPIC DRIFT ====================

    public function test_detect_topic_drift_with_enough_messages(): void
    {
        $session = $this->createSession(CoachId::MEETLY);

        // Add messages with different topics
        $session->addTurn(ConversationTurn::user('I have a presentation tomorrow'));
        $session->addTurn(ConversationTurn::assistant('I understand...'));
        $session->addTurn(ConversationTurn::user('Now I am angry about my boss'));
        $session->addTurn(ConversationTurn::assistant('Tell me more...'));
        $session->addTurn(ConversationTurn::user('My boss is so frustrating'));
        $session->addTurn(ConversationTurn::assistant('That sounds difficult...'));
        $session->addTurn(ConversationTurn::user('I want to quit and leave'));

        $drift = $this->detector->detectTopicDrift($session);

        // With very different topics, should detect drift
        $this->assertTrue($drift);
    }

    public function test_no_topic_drift_with_few_messages(): void
    {
        $session = $this->createSession(CoachId::MEETLY);

        $session->addTurn(ConversationTurn::user('Hello'));
        $session->addTurn(ConversationTurn::assistant('Hi there'));

        $drift = $this->detector->detectTopicDrift($session);

        $this->assertFalse($drift);
    }

    // ==================== RESULT STATIC CONSTRUCTORS ====================

    public function test_handoff_result_none(): void
    {
        $result = \Sisly\Dispatcher\HandoffResult::none();

        $this->assertFalse($result->suggested);
        $this->assertNull($result->suggestedCoach);
        $this->assertEquals(0.0, $result->confidence);
    }

    public function test_handoff_result_suggested(): void
    {
        $result = \Sisly\Dispatcher\HandoffResult::suggested(
            CoachId::VENTO,
            0.8,
            ['angry', 'frustrated']
        );

        $this->assertTrue($result->suggested);
        $this->assertEquals(CoachId::VENTO, $result->suggestedCoach);
        $this->assertEquals(0.8, $result->confidence);
        $this->assertEquals(['angry', 'frustrated'], $result->triggers);
    }

    // ==================== HELPER METHODS ====================

    private function createSession(CoachId $coachId): Session
    {
        return Session::create(
            id: 'test-session-' . uniqid(),
            coachId: $coachId,
            geo: new GeoContext(country: 'AE'),
            preferences: new SessionPreferences(),
        );
    }
}
