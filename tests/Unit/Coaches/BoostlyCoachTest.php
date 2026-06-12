<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Coaches;

use PHPUnit\Framework\TestCase;
use Sisly\Coaches\BoostlyCoach;
use Sisly\Coaches\PromptLoader;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\LLM\MockProvider;

class BoostlyCoachTest extends TestCase
{
    private BoostlyCoach $coach;
    private MockProvider $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockProvider = new MockProvider();
        $this->coach = new BoostlyCoach($this->mockProvider);
    }

    public function test_get_id_returns_boostly(): void
    {
        $this->assertEquals(CoachId::BOOSTLY, $this->coach->getId());
    }

    public function test_get_name_returns_boostly(): void
    {
        $this->assertEquals('BOOSTLY', $this->coach->getName());
    }

    public function test_get_description_contains_self_doubt_and_imposter(): void
    {
        $description = $this->coach->getDescription();

        $this->assertStringContainsString('self-doubt', strtolower($description));
        $this->assertStringContainsString('imposter', strtolower($description));
    }

    public function test_get_domains_returns_expected_domains(): void
    {
        $domains = $this->coach->getDomains();

        $this->assertIsArray($domains);
        $this->assertNotEmpty($domains);
        $this->assertContains('self-doubt', $domains);
        $this->assertContains('imposter syndrome', $domains);
        $this->assertContains('comparison spirals', $domains);
    }

    public function test_get_triggers_returns_expected_triggers(): void
    {
        $triggers = $this->coach->getTriggers();

        $this->assertIsArray($triggers);
        $this->assertNotEmpty($triggers);
        $this->assertContains('not good enough', $triggers);
        $this->assertContains('imposter', $triggers);
        $this->assertContains('fraud', $triggers);
        $this->assertContains('don\'t belong', $triggers);
    }

    public function test_can_handle_returns_true_for_self_doubt_messages(): void
    {
        $this->assertTrue($this->coach->canHandle("I'm not good enough for this role"));
        $this->assertTrue($this->coach->canHandle("I feel like a fraud at work"));
        $this->assertTrue($this->coach->canHandle("I don't belong in this team"));
    }

    public function test_can_handle_returns_true_for_imposter_keywords(): void
    {
        $this->assertTrue($this->coach->canHandle("I feel like an imposter"));
        $this->assertTrue($this->coach->canHandle("I'm just pretending to know what I'm doing"));
        $this->assertTrue($this->coach->canHandle("I doubt myself constantly"));
    }

    public function test_can_handle_is_case_insensitive(): void
    {
        $this->assertTrue($this->coach->canHandle("I'M NOT GOOD ENOUGH"));
        $this->assertTrue($this->coach->canHandle("I FEEL LIKE A FRAUD"));
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
        $this->assertStringContainsString('BOOSTLY', $prompt);
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
        $this->mockProvider->addResponse('doubt', 'The doubt is loud right now.');

        $session = $this->createSession();
        $result = $this->coach->process($session, "I'm not good enough for this job");

        $this->assertIsArray($result);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('arabic_mirror', $result);
        $this->assertArrayHasKey('coe_trace', $result);
    }

    public function test_process_returns_non_empty_response(): void
    {
        $session = $this->createSession();
        $result = $this->coach->process($session, 'Everyone else seems to know what they are doing except me');

        $this->assertNotEmpty($result['response']);
    }

    public function test_process_generates_arabic_mirror_when_enabled(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::BOOSTLY,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(arabicMirror: true),
        );

        $this->mockProvider->addResponse('doubt', 'الشك موجود بس مو هو الحقيقة');

        $result = $this->coach->process($session, "I feel like a fraud");

        $this->assertArrayHasKey('arabic_mirror', $result);
    }

    public function test_process_includes_coe_trace_when_enabled(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::BOOSTLY,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(includeCoETrace: true),
        );

        $result = $this->coach->process($session, "I don't belong in this team");

        $this->assertArrayHasKey('coe_trace', $result);
    }

    public function test_process_handles_llm_failure_gracefully(): void
    {
        $this->mockProvider->simulateError('API unavailable');

        $session = $this->createSession();
        $result = $this->coach->process($session, "I'm not good enough");

        $this->assertIsArray($result);
        $this->assertArrayHasKey('response', $result);
        $this->assertNotEmpty($result['response']);
    }

    public function test_process_response_is_under_word_limit(): void
    {
        $session = $this->createSession();
        $result = $this->coach->process($session, 'I feel like an imposter at my new job');

        $wordCount = str_word_count($result['response']);

        $this->assertLessThanOrEqual(50, $wordCount);
    }

    public function test_coach_with_custom_prompt_loader(): void
    {
        $customLoader = new PromptLoader();
        $coach = new BoostlyCoach($this->mockProvider, $customLoader);

        $this->assertEquals(CoachId::BOOSTLY, $coach->getId());
    }

    private function createSession(SessionState $state = SessionState::INTAKE): Session
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::BOOSTLY,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(),
        );

        if ($state !== SessionState::INTAKE) {
            $session->transitionTo($state);
        }

        return $session;
    }
}
