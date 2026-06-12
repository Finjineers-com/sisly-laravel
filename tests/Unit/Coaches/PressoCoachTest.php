<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Coaches;

use PHPUnit\Framework\TestCase;
use Sisly\Coaches\PressoCoach;
use Sisly\Coaches\PromptLoader;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\LLM\MockProvider;

class PressoCoachTest extends TestCase
{
    private PressoCoach $coach;
    private MockProvider $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockProvider = new MockProvider();
        $this->coach = new PressoCoach($this->mockProvider);
    }

    public function test_get_id_returns_presso(): void
    {
        $this->assertEquals(CoachId::PRESSO, $this->coach->getId());
    }

    public function test_get_name_returns_presso(): void
    {
        $this->assertEquals('PRESSO', $this->coach->getName());
    }

    public function test_get_description_contains_pressure_and_overwhelm(): void
    {
        $description = $this->coach->getDescription();

        $this->assertStringContainsString('pressure', strtolower($description));
        $this->assertStringContainsString('overwhelm', strtolower($description));
    }

    public function test_get_domains_returns_expected_domains(): void
    {
        $domains = $this->coach->getDomains();

        $this->assertIsArray($domains);
        $this->assertNotEmpty($domains);
        $this->assertContains('work overwhelm', $domains);
        $this->assertContains('deadline panic', $domains);
        $this->assertContains('analysis paralysis', $domains);
    }

    public function test_get_triggers_returns_expected_triggers(): void
    {
        $triggers = $this->coach->getTriggers();

        $this->assertIsArray($triggers);
        $this->assertNotEmpty($triggers);
        $this->assertContains('overwhelm', $triggers);
        $this->assertContains('deadline', $triggers);
        $this->assertContains('pressure', $triggers);
        $this->assertContains('too much', $triggers);
    }

    public function test_can_handle_returns_true_for_overwhelm_messages(): void
    {
        $this->assertTrue($this->coach->canHandle('I have too much work to do'));
        $this->assertTrue($this->coach->canHandle('The deadline is tomorrow and I am overwhelmed'));
        $this->assertTrue($this->coach->canHandle('I feel so much pressure right now'));
    }

    public function test_can_handle_returns_true_for_freeze_and_panic_keywords(): void
    {
        $this->assertTrue($this->coach->canHandle("I can't start anything"));
        $this->assertTrue($this->coach->canHandle('I feel paralyzed'));
        $this->assertTrue($this->coach->canHandle('Everything feels urgent'));
    }

    public function test_can_handle_is_case_insensitive(): void
    {
        $this->assertTrue($this->coach->canHandle('I AM SO OVERWHELMED'));
        $this->assertTrue($this->coach->canHandle('THE DEADLINE IS KILLING ME'));
    }

    public function test_can_handle_returns_false_for_unrelated_messages(): void
    {
        $this->assertFalse($this->coach->canHandle('I love my job'));
        $this->assertFalse($this->coach->canHandle('The weather is nice today'));
    }

    public function test_get_system_prompt_returns_non_empty_string(): void
    {
        $prompt = $this->coach->getSystemPrompt(SessionState::INTAKE);

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
        $this->assertStringContainsString('PRESSO', $prompt);
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
        $this->mockProvider->addResponse('overwhelmed', 'I hear that you are feeling overwhelmed with work.');

        $session = $this->createSession();
        $result = $this->coach->process($session, 'I am overwhelmed with too much work');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('arabic_mirror', $result);
        $this->assertArrayHasKey('coe_trace', $result);
    }

    public function test_process_returns_non_empty_response(): void
    {
        $session = $this->createSession();
        $result = $this->coach->process($session, 'I have 5 deadlines and everything is urgent');

        $this->assertNotEmpty($result['response']);
    }

    public function test_process_generates_arabic_mirror_when_enabled(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::PRESSO,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(arabicMirror: true),
        );

        $this->mockProvider->addResponse('overwhelmed', 'الضغط الآن عالي جداً');

        $result = $this->coach->process($session, 'I am overwhelmed');

        $this->assertArrayHasKey('arabic_mirror', $result);
    }

    public function test_process_includes_coe_trace_when_enabled(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::PRESSO,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(includeCoETrace: true),
        );

        $result = $this->coach->process($session, 'I am overwhelmed with deadlines');

        $this->assertArrayHasKey('coe_trace', $result);
    }

    public function test_process_handles_llm_failure_gracefully(): void
    {
        $this->mockProvider->simulateError('API unavailable');

        $session = $this->createSession();
        $result = $this->coach->process($session, 'I am overwhelmed');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('response', $result);
        $this->assertNotEmpty($result['response']);
    }

    public function test_process_response_is_under_word_limit(): void
    {
        $session = $this->createSession();
        $result = $this->coach->process($session, 'I have 5 deadlines and I am drowning');

        $wordCount = str_word_count($result['response']);

        $this->assertLessThanOrEqual(50, $wordCount);
    }

    public function test_coach_with_custom_prompt_loader(): void
    {
        $customLoader = new PromptLoader();
        $coach = new PressoCoach($this->mockProvider, $customLoader);

        $this->assertEquals(CoachId::PRESSO, $coach->getId());
    }

    private function createSession(SessionState $state = SessionState::INTAKE): Session
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::PRESSO,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(),
        );

        if ($state !== SessionState::INTAKE) {
            $session->transitionTo($state);
        }

        return $session;
    }
}
