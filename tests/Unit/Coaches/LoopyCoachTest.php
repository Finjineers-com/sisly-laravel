<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Coaches;

use PHPUnit\Framework\TestCase;
use Sisly\Coaches\LoopyCoach;
use Sisly\Coaches\PromptLoader;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\LLM\MockProvider;

class LoopyCoachTest extends TestCase
{
    private LoopyCoach $coach;
    private MockProvider $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockProvider = new MockProvider();
        $this->coach = new LoopyCoach($this->mockProvider);
    }

    public function test_get_id_returns_loopy(): void
    {
        $this->assertEquals(CoachId::LOOPY, $this->coach->getId());
    }

    public function test_get_name_returns_loopy(): void
    {
        $this->assertEquals('LOOPY', $this->coach->getName());
    }

    public function test_get_description_contains_rumination_and_overthinking(): void
    {
        $description = $this->coach->getDescription();

        $this->assertStringContainsString('rumination', strtolower($description));
        $this->assertStringContainsString('overthinking', strtolower($description));
    }

    public function test_get_domains_returns_expected_domains(): void
    {
        $domains = $this->coach->getDomains();

        $this->assertIsArray($domains);
        $this->assertNotEmpty($domains);
        $this->assertContains('rumination', $domains);
        $this->assertContains('overthinking', $domains);
        $this->assertContains('thought loops', $domains);
    }

    public function test_get_triggers_returns_expected_triggers(): void
    {
        $triggers = $this->coach->getTriggers();

        $this->assertIsArray($triggers);
        $this->assertNotEmpty($triggers);
        $this->assertContains('overthinking', $triggers);
        $this->assertContains('replaying', $triggers);
        $this->assertContains('what if', $triggers);
        $this->assertContains('spiraling', $triggers);
    }

    public function test_can_handle_returns_true_for_rumination_messages(): void
    {
        $this->assertTrue($this->coach->canHandle("I can't stop thinking about what happened"));
        $this->assertTrue($this->coach->canHandle("I keep replaying that conversation"));
        $this->assertTrue($this->coach->canHandle("What if everything goes wrong"));
    }

    public function test_can_handle_returns_true_for_overthinking_keywords(): void
    {
        $this->assertTrue($this->coach->canHandle("I'm overthinking this"));
        $this->assertTrue($this->coach->canHandle("My mind is spinning"));
        $this->assertTrue($this->coach->canHandle("I'm stuck in my head"));
    }

    public function test_can_handle_is_case_insensitive(): void
    {
        $this->assertTrue($this->coach->canHandle("I'M OVERTHINKING EVERYTHING"));
        $this->assertTrue($this->coach->canHandle("MY MIND IS SPIRALING"));
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
        $this->assertStringContainsString('LOOPY', $prompt);
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
        $this->mockProvider->addResponse('thinking', 'I hear that your mind is stuck on repeat.');

        $session = $this->createSession();
        $result = $this->coach->process($session, "I can't stop thinking about what I said");

        $this->assertIsArray($result);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('arabic_mirror', $result);
        $this->assertArrayHasKey('coe_trace', $result);
    }

    public function test_process_returns_non_empty_response(): void
    {
        $session = $this->createSession();
        $result = $this->coach->process($session, 'I keep replaying that meeting in my head over and over');

        $this->assertNotEmpty($result['response']);
    }

    public function test_process_generates_arabic_mirror_when_enabled(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::LOOPY,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(arabicMirror: true),
        );

        $this->mockProvider->addResponse('thinking', 'عقلك يكرر نفس الفكرة');

        $result = $this->coach->process($session, "I can't stop overthinking");

        $this->assertArrayHasKey('arabic_mirror', $result);
    }

    public function test_process_includes_coe_trace_when_enabled(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::LOOPY,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(includeCoETrace: true),
        );

        $result = $this->coach->process($session, "My mind won't stop spinning");

        $this->assertArrayHasKey('coe_trace', $result);
    }

    public function test_process_handles_llm_failure_gracefully(): void
    {
        $this->mockProvider->simulateError('API unavailable');

        $session = $this->createSession();
        $result = $this->coach->process($session, "I'm overthinking everything");

        $this->assertIsArray($result);
        $this->assertArrayHasKey('response', $result);
        $this->assertNotEmpty($result['response']);
    }

    public function test_process_response_is_under_word_limit(): void
    {
        $session = $this->createSession();
        $result = $this->coach->process($session, 'I keep replaying what she said to me last week');

        $wordCount = str_word_count($result['response']);

        $this->assertLessThanOrEqual(50, $wordCount);
    }

    public function test_coach_with_custom_prompt_loader(): void
    {
        $customLoader = new PromptLoader();
        $coach = new LoopyCoach($this->mockProvider, $customLoader);

        $this->assertEquals(CoachId::LOOPY, $coach->getId());
    }

    private function createSession(SessionState $state = SessionState::INTAKE): Session
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::LOOPY,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(),
        );

        if ($state !== SessionState::INTAKE) {
            $session->transitionTo($state);
        }

        return $session;
    }
}
