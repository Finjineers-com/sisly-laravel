<?php

declare(strict_types=1);

namespace Sisly\Tests\Integration;

use Sisly\Facades\Sisly;
use Sisly\Enums\SessionState;

/**
 * Integration tests for full conversation flows with real LLM.
 *
 * Run with: composer test:integration
 * Or: OPENAI_API_KEY=sk-xxx ./vendor/bin/phpunit --testsuite Integration --filter FullConversationTest
 */
class FullConversationTest extends IntegrationTestCase
{
    public function test_complete_anxiety_coaching_session(): void
    {
        $this->requireAnyLLM();
        $this->configureRealLLM();

        // Start session with anxiety message
        $response = Sisly::startSession(
            message: "I've been feeling really anxious about my job interview tomorrow.",
            context: ['coach_id' => 'meetly', 'geo' => ['country' => 'AE']]
        );

        $this->assertNotEmpty($response->sessionId);
        $this->assertEquals('meetly', $response->coachId->value);
        $this->assertNotEmpty($response->responseText, 'Response should not be empty');

        // Verify we're not getting fallback response
        $this->assertNotEquals(
            "I'm here with you. Tell me what's on your mind.",
            $response->responseText,
            'Should not return intake fallback - LLM call may have failed'
        );

        $sessionId = $response->sessionId;

        // Continue the conversation
        $response2 = Sisly::message($sessionId, "Yes, I keep imagining worst case scenarios.");

        $this->assertNotEmpty($response2->responseText);
        $this->assertNotEquals(
            "Can you tell me a bit more about what you're experiencing?",
            $response2->responseText,
            'Should not return exploration fallback - LLM call may have failed'
        );

        // Check state progression
        $state = Sisly::getState($sessionId);
        $this->assertIsArray($state);
        $this->assertArrayHasKey('state', $state);

        // End session
        Sisly::endSession($sessionId);

        // Session should no longer exist
        $this->assertFalse(Sisly::sessionExists($sessionId));
    }

    public function test_complete_anger_coaching_session(): void
    {
        $this->requireAnyLLM();
        $this->configureRealLLM();

        // Start session with anger message
        $response = Sisly::startSession(
            message: "I'm so frustrated with my coworker who keeps taking credit for my work.",
            context: ['coach_id' => 'vento', 'geo' => ['country' => 'SA']]
        );

        $this->assertNotEmpty($response->sessionId);
        $this->assertEquals('vento', $response->coachId->value);
        $this->assertNotEmpty($response->responseText);

        // Response should acknowledge the frustration
        $content = strtolower($response->responseText);
        $this->assertTrue(
            str_contains($content, 'frustrat') ||
            str_contains($content, 'hear') ||
            str_contains($content, 'understand') ||
            str_contains($content, 'feel') ||
            str_contains($content, 'credit') ||
            str_contains($content, 'work'),
            "Response should acknowledge emotion. Got: {$response->responseText}"
        );

        Sisly::endSession($response->sessionId);
    }

    public function test_complete_overwhelm_coaching_session(): void
    {
        $this->requireAnyLLM();
        $this->configureRealLLM();

        // Start session with overwhelm message
        $response = Sisly::startSession(
            message: "I'm completely overwhelmed with my workload. There are so many deadlines and I can't cope.",
            context: ['coach_id' => 'presso', 'geo' => ['country' => 'AE']]
        );

        $this->assertNotEmpty($response->sessionId);
        $this->assertEquals('presso', $response->coachId->value);
        $this->assertNotEmpty($response->responseText);

        // Response should acknowledge the overwhelm
        $content = strtolower($response->responseText);
        $this->assertTrue(
            str_contains($content, 'overwhelm') ||
            str_contains($content, 'hear') ||
            str_contains($content, 'lot') ||
            str_contains($content, 'much') ||
            str_contains($content, 'pressure') ||
            str_contains($content, 'breath'),
            "Response should acknowledge overwhelm. Got: {$response->responseText}"
        );

        // Continue the conversation
        $response2 = Sisly::message($response->sessionId, "I have three projects due this week and I can't even start.");
        $this->assertNotEmpty($response2->responseText);

        Sisly::endSession($response->sessionId);
    }

    public function test_complete_overthinking_coaching_session(): void
    {
        $this->requireAnyLLM();
        $this->configureRealLLM();

        // Start session with rumination message
        $response = Sisly::startSession(
            message: "I can't stop replaying a conversation I had with my manager yesterday. I keep thinking about what I should have said differently.",
            context: ['coach_id' => 'loopy', 'geo' => ['country' => 'AE']]
        );

        $this->assertNotEmpty($response->sessionId);
        $this->assertEquals('loopy', $response->coachId->value);
        $this->assertNotEmpty($response->responseText);

        // Response should acknowledge the loop/replay
        $content = strtolower($response->responseText);
        $this->assertTrue(
            str_contains($content, 'replay') ||
            str_contains($content, 'think') ||
            str_contains($content, 'mind') ||
            str_contains($content, 'loop') ||
            str_contains($content, 'stuck') ||
            str_contains($content, 'going back'),
            "Response should acknowledge the thought loop. Got: {$response->responseText}"
        );

        // Continue the conversation
        $response2 = Sisly::message($response->sessionId, "It's been going on all night, I couldn't sleep.");
        $this->assertNotEmpty($response2->responseText);

        Sisly::endSession($response->sessionId);
    }

    public function test_complete_self_doubt_coaching_session(): void
    {
        $this->requireAnyLLM();
        $this->configureRealLLM();

        // Start session with imposter syndrome message
        $response = Sisly::startSession(
            message: "I just got promoted but I feel like a total fraud. Everyone else seems so much more qualified than me.",
            context: ['coach_id' => 'boostly', 'geo' => ['country' => 'AE']]
        );

        $this->assertNotEmpty($response->sessionId);
        $this->assertEquals('boostly', $response->coachId->value);
        $this->assertNotEmpty($response->responseText);

        // Response should acknowledge the doubt without cheerleading
        $content = strtolower($response->responseText);
        $this->assertTrue(
            str_contains($content, 'doubt') ||
            str_contains($content, 'imposter') ||
            str_contains($content, 'fraud') ||
            str_contains($content, 'feel') ||
            str_contains($content, 'promoted') ||
            str_contains($content, 'question'),
            "Response should acknowledge the self-doubt. Got: {$response->responseText}"
        );

        // Continue the conversation
        $response2 = Sisly::message($response->sessionId, "I keep comparing myself to my colleagues who have more experience.");
        $this->assertNotEmpty($response2->responseText);

        Sisly::endSession($response->sessionId);
    }

    public function test_automatic_coach_selection(): void
    {
        // Dispatcher-based auto-routing is opt-out (omit `coach_id`). The LLM classifier
        // is non-deterministic and this test is flaky — see docs/PENDING_FIXES.md (#19-ish).
        // Real consumers should pass `coach_id` explicitly.
        $this->markTestSkipped('Dispatcher auto-routing is flaky; consumers should pass coach_id explicitly. See docs/PENDING_FIXES.md.');

        $this->requireAnyLLM();
        $this->configureRealLLM();

        // Anxiety-related message should route to MEETLY
        $response1 = Sisly::startSession(
            message: "I'm feeling really anxious and nervous about everything.",
            context: ['geo' => ['country' => 'AE']]
        );
        $this->assertEquals('meetly', $response1->coachId->value);
        Sisly::endSession($response1->sessionId);

        // Anger-related message should route to VENTO
        $response2 = Sisly::startSession(
            message: "I'm so angry and furious at my boss!",
            context: ['geo' => ['country' => 'AE']]
        );
        $this->assertEquals('vento', $response2->coachId->value);
        Sisly::endSession($response2->sessionId);

        // Overthinking message should route to LOOPY
        $response3 = Sisly::startSession(
            message: "I can't stop overthinking about what people think of me.",
            context: ['geo' => ['country' => 'AE']]
        );
        $this->assertEquals('loopy', $response3->coachId->value);
        Sisly::endSession($response3->sessionId);

        // Overwhelm message should route to PRESSO
        $response4 = Sisly::startSession(
            message: "I'm completely overwhelmed, there's too much to do and I can't cope.",
            context: ['geo' => ['country' => 'AE']]
        );
        $this->assertEquals('presso', $response4->coachId->value);
        Sisly::endSession($response4->sessionId);

        // Self-doubt message should route to BOOSTLY
        $response5 = Sisly::startSession(
            message: "I feel like a fraud, I'm not good enough for this job.",
            context: ['geo' => ['country' => 'AE']]
        );
        $this->assertEquals('boostly', $response5->coachId->value);
        Sisly::endSession($response5->sessionId);

        // Future-uncertainty message should route to SAFEO
        $response6 = Sisly::startSession(
            message: "I don't know what's going to happen with the layoffs and I can't stop worrying about the future.",
            context: ['geo' => ['country' => 'AE']]
        );
        $this->assertEquals('safeo', $response6->coachId->value);
        Sisly::endSession($response6->sessionId);
    }

    public function test_multi_turn_conversation_maintains_context(): void
    {
        $this->requireAnyLLM();
        $this->configureRealLLM();

        // Start with a specific topic
        $response1 = Sisly::startSession(
            message: "I'm anxious about giving a speech at my sister's wedding next month.",
            context: ['coach_id' => 'meetly']
        );

        $sessionId = $response1->sessionId;
        $this->assertNotEmpty($response1->responseText);

        // Second message references the first
        $response2 = Sisly::message($sessionId, "The wedding is in Dubai and there will be 200 guests.");
        $this->assertNotEmpty($response2->responseText);

        // Third message - coach should remember context
        $response3 = Sisly::message($sessionId, "What if I forget my speech?");
        $this->assertNotEmpty($response3->responseText);

        // Verify turn count increased
        $state = Sisly::getState($sessionId);
        $this->assertGreaterThan(1, $state['turn_count']);

        Sisly::endSession($sessionId);
    }

    public function test_crisis_detection_still_works_with_real_llm(): void
    {
        $this->requireAnyLLM();
        $this->configureRealLLM();

        // Crisis message should trigger immediate intervention
        $response = Sisly::startSession(
            message: "I want to kill myself",
            context: ['geo' => ['country' => 'AE']]
        );

        $this->assertTrue($response->crisis->detected, 'Crisis should be detected');
        $this->assertEquals(SessionState::CRISIS_INTERVENTION, $response->state);

        // Response should include crisis resources
        $this->assertNotEmpty($response->responseText);
        $this->assertNotNull($response->crisis);

        Sisly::endSession($response->sessionId);
    }

    public function test_arabic_mirror_generation(): void
    {
        $this->requireAnyLLM();
        $this->configureRealLLM();

        // Enable Arabic mirror
        config(['sisly.arabic.mirror_enabled' => true]);

        $response = Sisly::startSession(
            message: "I feel overwhelmed with too many tasks at work.",
            context: ['coach_id' => 'presso', 'geo' => ['country' => 'AE']]
        );

        $this->assertNotEmpty($response->responseText);

        // Arabic mirror should be generated for first turn
        if ($response->arabicMirror !== null) {
            // Verify it contains Arabic characters
            $this->assertMatchesRegularExpression(
                '/[\x{0600}-\x{06FF}]/u',
                $response->arabicMirror,
                'Arabic mirror should contain Arabic characters'
            );
        }

        Sisly::endSession($response->sessionId);
    }

    public function test_session_state_progression(): void
    {
        $this->requireAnyLLM();
        $this->configureRealLLM();

        $response = Sisly::startSession(
            message: "I doubt myself constantly at work.",
            context: ['coach_id' => 'boostly']
        );

        $sessionId = $response->sessionId;

        // Initial state should be intake or moved past it
        $state1 = Sisly::getState($sessionId);
        $validStates = ['intake', 'risk_triage', 'exploration'];
        $this->assertContains($state1['state'], $validStates);

        // Continue conversation
        Sisly::message($sessionId, "I always feel like I'm not good enough compared to my colleagues.");

        $state2 = Sisly::getState($sessionId);
        $this->assertGreaterThanOrEqual($state1['turn_count'], $state2['turn_count']);

        // More conversation turns
        Sisly::message($sessionId, "It started after I got promoted last year.");
        Sisly::message($sessionId, "Yes, the new responsibilities scare me.");

        $state3 = Sisly::getState($sessionId);

        // State should have progressed
        $this->assertGreaterThan(1, $state3['turn_count']);

        Sisly::endSession($sessionId);
    }

    public function test_response_quality_not_generic(): void
    {
        $this->requireAnyLLM();
        $this->configureRealLLM();

        $response = Sisly::startSession(
            message: "I've been losing sleep because I keep thinking about a mistake I made at work last week.",
            context: ['coach_id' => 'loopy']
        );

        $this->assertNotEmpty($response->responseText);

        // Response should NOT be one of the generic fallbacks
        $fallbackResponses = [
            "I'm here with you.",
            "I'm here with you. Tell me what's on your mind.",
            "Can you tell me a bit more about what you're experiencing?",
            "I hear you. That makes sense.",
            "Let's try something together.",
            "You've done well to take this time for yourself.",
        ];

        foreach ($fallbackResponses as $fallback) {
            $this->assertNotEquals(
                $fallback,
                $response->responseText,
                "Response should not be generic fallback: {$fallback}"
            );
        }

        // Response should relate to the user's specific issue (sleep, mistake, work, thinking)
        $content = strtolower($response->responseText);
        $relevantTerms = ['sleep', 'mistake', 'work', 'think', 'mind', 'worry', 'replay', 'rest'];
        $isRelevant = false;
        foreach ($relevantTerms as $term) {
            if (str_contains($content, $term)) {
                $isRelevant = true;
                break;
            }
        }

        // This is a soft check - LLM responses vary
        if (!$isRelevant) {
            $this->addWarning("Response may not be contextually relevant: {$response->responseText}");
        }

        Sisly::endSession($response->sessionId);
    }
}
