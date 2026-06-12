<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Coaches;

use PHPUnit\Framework\TestCase;
use Sisly\Coaches\PromptLoader;
use Sisly\Coaches\SafeoCoach;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\LLM\MockProvider;

class SafeoCoachTest extends TestCase
{
    private SafeoCoach $coach;
    private MockProvider $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockProvider = new MockProvider();
        $this->coach = new SafeoCoach($this->mockProvider);
    }

    public function test_get_id_returns_safeo(): void
    {
        $this->assertEquals(CoachId::SAFEO, $this->coach->getId());
    }

    public function test_get_name_returns_safeo(): void
    {
        $this->assertEquals('SAFEO', $this->coach->getName());
    }

    public function test_get_description_mentions_uncertainty(): void
    {
        $description = strtolower($this->coach->getDescription());

        $this->assertStringContainsString('uncertainty', $description);
    }

    public function test_role_description_localizes(): void
    {
        $en = $this->coach->getRoleDescription('en');
        $ar = $this->coach->getRoleDescription('ar');

        $this->assertNotEmpty($en);
        $this->assertNotEmpty($ar);
        $this->assertNotSame($en, $ar);

        // EN should be ASCII-only
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $en);
        // AR should contain Arabic characters
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $ar);
    }

    public function test_get_domains_returns_uncertainty_domains(): void
    {
        $domains = $this->coach->getDomains();

        $this->assertIsArray($domains);
        $this->assertNotEmpty($domains);
        $this->assertContains('uncertainty', $domains);
        $this->assertContains('regional tension', $domains);
        $this->assertContains('job insecurity', $domains);
    }

    public function test_get_triggers_returns_expected_triggers(): void
    {
        $triggers = $this->coach->getTriggers();

        $this->assertIsArray($triggers);
        $this->assertNotEmpty($triggers);
        $this->assertContains('uncertain', $triggers);
        $this->assertContains('layoffs', $triggers);
        $this->assertContains('big decision', $triggers);
        $this->assertContains('future', $triggers);
    }

    public function test_can_handle_returns_true_for_uncertainty_messages(): void
    {
        $this->assertTrue($this->coach->canHandle("I don't know what's going to happen with the layoffs."));
        $this->assertTrue($this->coach->canHandle("Everything feels so unstable right now."));
        $this->assertTrue($this->coach->canHandle("I have a big decision and I don't know what to do."));
    }

    public function test_can_handle_returns_true_for_future_anxiety(): void
    {
        $this->assertTrue($this->coach->canHandle("I'm scared of the future."));
        $this->assertTrue($this->coach->canHandle("I can't decide whether to leave."));
    }

    public function test_can_handle_is_case_insensitive(): void
    {
        $this->assertTrue($this->coach->canHandle("EVERYTHING IS UNCERTAIN"));
    }

    public function test_can_handle_returns_false_for_unrelated_messages(): void
    {
        $this->assertFalse($this->coach->canHandle('I love my job.'));
        $this->assertFalse($this->coach->canHandle('The weather is nice today.'));
    }

    public function test_get_system_prompt_returns_non_empty_string(): void
    {
        $prompt = $this->coach->getSystemPrompt(SessionState::INTAKE);

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
        $this->assertStringContainsString('SAFEO', $prompt);
    }

    public function test_get_system_prompt_does_not_claim_clinical_credentials(): void
    {
        $prompt = strtolower($this->coach->getSystemPrompt(SessionState::INTAKE));

        // The persona is informed by long experience but must never CLAIM to be a clinician.
        // The system.md must explicitly disclaim these in the "What I Never Do" / inner-orientation block.
        $this->assertStringNotContainsString('i am a psychologist', $prompt);
        $this->assertStringNotContainsString('i am a therapist', $prompt);
        $this->assertStringNotContainsString('i am a doctor', $prompt);
        $this->assertStringNotContainsString('i am a clinician', $prompt);
    }

    public function test_get_state_prompt_returns_non_empty_for_each_state(): void
    {
        foreach ([
            SessionState::EXPLORATION,
            SessionState::DEEPENING,
            SessionState::PROBLEM_SOLVING,
            SessionState::CLOSING,
        ] as $state) {
            $prompt = $this->coach->getStatePrompt($state);
            $this->assertIsString($prompt);
            $this->assertNotEmpty($prompt, "State prompt for {$state->value} should be non-empty");
        }
    }

    public function test_process_returns_response_array(): void
    {
        $session = $this->createSession();
        $result = $this->coach->process($session, "Everything feels so uncertain right now.");

        $this->assertIsArray($result);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('arabic_mirror', $result);
        $this->assertArrayHasKey('coe_trace', $result);
    }

    public function test_process_returns_non_empty_response(): void
    {
        $session = $this->createSession();
        $result = $this->coach->process($session, "I don't know what's going to happen with my job.");

        $this->assertNotEmpty($result['response']);
    }

    public function test_process_handles_llm_failure_gracefully(): void
    {
        $this->mockProvider->simulateError('API unavailable');

        $session = $this->createSession();
        $result = $this->coach->process($session, "I'm scared of the future.");

        $this->assertIsArray($result);
        $this->assertArrayHasKey('response', $result);
        $this->assertNotEmpty($result['response']);
    }

    public function test_greetings_are_bilingual_pairs(): void
    {
        $greetings = $this->coach->getGreetings();

        $this->assertIsArray($greetings);
        $this->assertNotEmpty($greetings);

        foreach ($greetings as $pair) {
            $this->assertArrayHasKey('en', $pair);
            $this->assertArrayHasKey('ar', $pair);
            $this->assertNotEmpty($pair['en']);
            $this->assertNotEmpty($pair['ar']);
            // AR greeting must contain Arabic characters
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $pair['ar']);
        }
    }

    public function test_get_greeting_returns_string_in_requested_language(): void
    {
        $en = $this->coach->getGreeting('en');
        $ar = $this->coach->getGreeting('ar');

        $this->assertIsString($en);
        $this->assertIsString($ar);
        $this->assertNotEmpty($en);
        $this->assertNotEmpty($ar);
    }

    public function test_coach_with_custom_prompt_loader(): void
    {
        $customLoader = new PromptLoader();
        $coach = new SafeoCoach($this->mockProvider, $customLoader);

        $this->assertEquals(CoachId::SAFEO, $coach->getId());
    }

    private function createSession(SessionState $state = SessionState::INTAKE): Session
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::SAFEO,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(),
        );

        if ($state !== SessionState::INTAKE) {
            $session->transitionTo($state);
        }

        return $session;
    }
}
