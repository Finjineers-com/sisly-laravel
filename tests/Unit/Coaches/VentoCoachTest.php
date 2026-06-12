<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Coaches;

use PHPUnit\Framework\TestCase;
use Sisly\Coaches\PromptLoader;
use Sisly\Coaches\VentoCoach;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\LLM\MockProvider;

class VentoCoachTest extends TestCase
{
    private VentoCoach $coach;
    private MockProvider $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockProvider = new MockProvider();
        $this->coach = new VentoCoach($this->mockProvider);
    }

    public function test_get_id_returns_vento(): void
    {
        $this->assertEquals(CoachId::VENTO, $this->coach->getId());
    }

    public function test_get_name_returns_vento(): void
    {
        $this->assertEquals('VENTO', $this->coach->getName());
    }

    public function test_get_description_contains_anger_and_frustration(): void
    {
        $description = $this->coach->getDescription();

        $this->assertStringContainsString('anger', strtolower($description));
        $this->assertStringContainsString('frustration', strtolower($description));
    }

    public function test_get_domains_returns_expected_domains(): void
    {
        $domains = $this->coach->getDomains();

        $this->assertIsArray($domains);
        $this->assertNotEmpty($domains);
        $this->assertContains('anger release', $domains);
        $this->assertContains('frustration venting', $domains);
        $this->assertContains('feeling disrespected', $domains);
    }

    public function test_get_triggers_returns_expected_triggers(): void
    {
        $triggers = $this->coach->getTriggers();

        $this->assertIsArray($triggers);
        $this->assertNotEmpty($triggers);
        $this->assertContains('angry', $triggers);
        $this->assertContains('frustrated', $triggers);
        $this->assertContains('furious', $triggers);
        $this->assertContains('vent', $triggers);
    }

    public function test_can_handle_returns_true_for_anger_messages(): void
    {
        $this->assertTrue($this->coach->canHandle("I'm so angry at my coworker"));
        $this->assertTrue($this->coach->canHandle("I'm furious about what happened"));
        $this->assertTrue($this->coach->canHandle('I feel so frustrated with my boss'));
    }

    public function test_can_handle_returns_true_for_venting_keywords(): void
    {
        $this->assertTrue($this->coach->canHandle('I just need to vent'));
        $this->assertTrue($this->coach->canHandle('I feel disrespected'));
        $this->assertTrue($this->coach->canHandle("This is so unfair"));
    }

    public function test_can_handle_is_case_insensitive(): void
    {
        $this->assertTrue($this->coach->canHandle("I AM SO ANGRY"));
        $this->assertTrue($this->coach->canHandle('THIS IS INFURIATING'));
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
        $this->assertStringContainsString('VENTO', $prompt);
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
        $this->mockProvider->addResponse('angry', 'I hear that you are feeling angry.');

        $session = $this->createSession();
        $result = $this->coach->process($session, "I'm so angry at my manager");

        $this->assertIsArray($result);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('arabic_mirror', $result);
        $this->assertArrayHasKey('coe_trace', $result);
    }

    public function test_process_returns_non_empty_response(): void
    {
        $session = $this->createSession();
        $result = $this->coach->process($session, 'My coworker took credit for my work and I am furious');

        $this->assertNotEmpty($result['response']);
    }

    public function test_process_generates_arabic_mirror_when_enabled(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::VENTO,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(arabicMirror: true),
        );

        $this->mockProvider->addResponse('angry', 'حق لك تزعل');

        $result = $this->coach->process($session, "I'm so angry");

        $this->assertArrayHasKey('arabic_mirror', $result);
    }

    public function test_process_includes_coe_trace_when_enabled(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::VENTO,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(includeCoETrace: true),
        );

        $result = $this->coach->process($session, "I'm furious at my boss");

        $this->assertArrayHasKey('coe_trace', $result);
    }

    public function test_process_handles_llm_failure_gracefully(): void
    {
        $this->mockProvider->simulateError('API unavailable');

        $session = $this->createSession();
        $result = $this->coach->process($session, "I'm so angry");

        $this->assertIsArray($result);
        $this->assertArrayHasKey('response', $result);
        $this->assertNotEmpty($result['response']);
    }

    public function test_process_response_is_under_word_limit(): void
    {
        $session = $this->createSession();
        $result = $this->coach->process($session, 'My coworker disrespected me in the meeting');

        $wordCount = str_word_count($result['response']);

        $this->assertLessThanOrEqual(50, $wordCount);
    }

    public function test_coach_with_custom_prompt_loader(): void
    {
        $customLoader = new PromptLoader();
        $coach = new VentoCoach($this->mockProvider, $customLoader);

        $this->assertEquals(CoachId::VENTO, $coach->getId());
    }

    private function createSession(SessionState $state = SessionState::INTAKE): Session
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::VENTO,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(),
        );

        if ($state !== SessionState::INTAKE) {
            $session->transitionTo($state);
        }

        return $session;
    }
}
