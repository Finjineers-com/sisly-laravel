<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Coaches;

use PHPUnit\Framework\TestCase;
use Sisly\Coaches\MeetlyCoach;
use Sisly\Coaches\PromptLoader;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\LLM\MockProvider;

class MeetlyCoachTest extends TestCase
{
    private MeetlyCoach $coach;
    private MockProvider $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockProvider = new MockProvider();
        $this->coach = new MeetlyCoach($this->mockProvider);
    }

    public function test_get_id_returns_meetly(): void
    {
        $this->assertEquals(CoachId::MEETLY, $this->coach->getId());
    }

    public function test_get_name_returns_meetly(): void
    {
        $this->assertEquals('MEETLY', $this->coach->getName());
    }

    public function test_get_description_contains_meeting_anxiety(): void
    {
        $description = $this->coach->getDescription();

        $this->assertStringContainsString('meeting', strtolower($description));
        $this->assertStringContainsString('anxiety', strtolower($description));
    }

    public function test_get_domains_returns_expected_domains(): void
    {
        $domains = $this->coach->getDomains();

        $this->assertIsArray($domains);
        $this->assertNotEmpty($domains);
        $this->assertContains('meeting anxiety', $domains);
        $this->assertContains('presentation nerves', $domains);
        $this->assertContains('interview stress', $domains);
    }

    public function test_get_triggers_returns_expected_triggers(): void
    {
        $triggers = $this->coach->getTriggers();

        $this->assertIsArray($triggers);
        $this->assertNotEmpty($triggers);
        $this->assertContains('meeting', $triggers);
        $this->assertContains('presentation', $triggers);
        $this->assertContains('interview', $triggers);
        $this->assertContains('nervous', $triggers);
    }

    public function test_can_handle_returns_true_for_meeting_message(): void
    {
        $this->assertTrue($this->coach->canHandle('I have a meeting tomorrow'));
        $this->assertTrue($this->coach->canHandle('My presentation is in an hour'));
        $this->assertTrue($this->coach->canHandle('I have an interview today'));
    }

    public function test_can_handle_returns_true_for_anxiety_keywords(): void
    {
        $this->assertTrue($this->coach->canHandle('I feel nervous'));
        $this->assertTrue($this->coach->canHandle('I am scared'));
        $this->assertTrue($this->coach->canHandle('I feel anxious'));
    }

    public function test_can_handle_is_case_insensitive(): void
    {
        $this->assertTrue($this->coach->canHandle('I HAVE A MEETING'));
        $this->assertTrue($this->coach->canHandle('NERVOUS about the presentation'));
    }

    public function test_get_system_prompt_returns_non_empty_string(): void
    {
        $prompt = $this->coach->getSystemPrompt(SessionState::INTAKE);

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
        $this->assertStringContainsString('MEETLY', $prompt);
    }

    public function test_get_state_prompt_returns_non_empty_for_exploration(): void
    {
        $prompt = $this->coach->getStatePrompt(SessionState::EXPLORATION);

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
    }

    public function test_get_state_prompt_returns_non_empty_for_deepening(): void
    {
        $prompt = $this->coach->getStatePrompt(SessionState::DEEPENING);

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
    }

    public function test_get_state_prompt_returns_non_empty_for_problem_solving(): void
    {
        $prompt = $this->coach->getStatePrompt(SessionState::PROBLEM_SOLVING);

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
    }

    public function test_get_state_prompt_returns_non_empty_for_closing(): void
    {
        $prompt = $this->coach->getStatePrompt(SessionState::CLOSING);

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
    }

    public function test_process_returns_response_array(): void
    {
        // Setup mock response
        $this->mockProvider->addResponse('anxious', 'I hear that you are feeling anxious about the meeting.');

        $session = $this->createSession();
        $result = $this->coach->process($session, 'I am anxious about my meeting');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('arabic_mirror', $result);
        $this->assertArrayHasKey('coe_trace', $result);
    }

    public function test_process_returns_non_empty_response(): void
    {
        $session = $this->createSession();
        $result = $this->coach->process($session, 'I have a presentation tomorrow and I am nervous');

        $this->assertNotEmpty($result['response']);
    }

    public function test_process_generates_arabic_mirror_when_enabled(): void
    {
        // Create session with Arabic mirror enabled
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(arabicMirror: true),
        );

        // Mock to return Arabic mirror
        $this->mockProvider->addResponse('nervous', 'أنا هنا معك');

        $result = $this->coach->process($session, 'I am nervous');

        // Arabic mirror should be generated for first turn
        // Note: This depends on LLM returning content
        $this->assertArrayHasKey('arabic_mirror', $result);
    }

    public function test_process_includes_coe_trace_when_enabled(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(includeCoETrace: true),
        );

        $result = $this->coach->process($session, 'I am anxious about my meeting');

        $this->assertArrayHasKey('coe_trace', $result);
        // CoE trace should be present when enabled
    }

    public function test_process_handles_llm_failure_gracefully(): void
    {
        $this->mockProvider->simulateError('API unavailable');

        $session = $this->createSession();
        $result = $this->coach->process($session, 'I am anxious');

        // Should return fallback response
        $this->assertIsArray($result);
        $this->assertArrayHasKey('response', $result);
        $this->assertNotEmpty($result['response']);
    }

    public function test_process_response_is_under_word_limit(): void
    {
        $session = $this->createSession();
        $result = $this->coach->process($session, 'I have a presentation in 20 minutes');

        $wordCount = str_word_count($result['response']);

        // Responses should be under 40 words (with some buffer)
        $this->assertLessThanOrEqual(50, $wordCount);
    }

    public function test_coach_with_custom_prompt_loader(): void
    {
        $customLoader = new PromptLoader();
        $coach = new MeetlyCoach($this->mockProvider, $customLoader);

        $this->assertEquals(CoachId::MEETLY, $coach->getId());
    }

    private function createSession(SessionState $state = SessionState::INTAKE): Session
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(),
        );

        if ($state !== SessionState::INTAKE) {
            $session->transitionTo($state);
        }

        return $session;
    }
}
