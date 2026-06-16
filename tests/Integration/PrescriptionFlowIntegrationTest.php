<?php

declare(strict_types=1);

namespace Sisly\Tests\Integration;

use Illuminate\Support\Facades\Http;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\Facades\Sisly;
use Sisly\LLM\MockProvider;
use Sisly\Tests\TestCase;

class PrescriptionFlowIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Force mock driver
        config(['sisly.llm.driver' => 'mock']);
    }

    public function test_prescription_flow_end_to_end(): void
    {
        // 1. Mock the Insights API response
        $mockInsights = [
            [
                'content_id' => 999,
                'title' => 'Settle Your Mind',
                'description' => 'A short session to relax',
                'duration' => 2,
                'media_category' => 'Sound',
                'media' => [
                    'audio_path' => 'https://sisly.com/999.mp3',
                    'audio_thumbnail' => 'https://sisly.com/999.png',
                ]
            ]
        ];

        Http::fake([
            '*/v1/insights/by-type*' => Http::response($mockInsights, 200),
        ]);

        // 2. Configure mock LLM response to emit the sisly block
        $mockProvider = app(LLMProviderInterface::class);
        $this->assertInstanceOf(MockProvider::class, $mockProvider);

        $mockProvider->addResponse(
            'trigger-prescription',
            "I suggest you listen to this sound.```sisly\n{\n  \"content_type\": \"Quiet mind\",\n  \"reason\": \"This will help ground your racing thoughts.\"\n}\n```"
        );

        // 3. Start session with Loopy coach
        $response = Sisly::startSession(
            message: 'I cannot stop thinking about my work today.',
            context: ['coach_id' => 'loopy']
        );

        $this->assertNotEmpty($response->sessionId);
        $this->assertNull($response->prescription); // No prescription on turn 1

        // 4. Force FSM state to PROBLEM_SOLVING so prescription is allowed
        $session = Sisly::getSession($response->sessionId);
        $this->assertNotNull($session);
        $session->transitionTo(SessionState::PROBLEM_SOLVING);
        app(\Sisly\Contracts\SessionStoreInterface::class)->save($session);

        // 5. Send message triggering the prescription
        $messageResponse = Sisly::message($response->sessionId, 'trigger-prescription');

        // 6. Assert resolved prescription exists on response
        $this->assertNotNull($messageResponse->prescription);
        $this->assertEquals(999, $messageResponse->prescription->contentId);
        $this->assertEquals('Settle Your Mind', $messageResponse->prescription->title);
        $this->assertEquals('This will help ground your racing thoughts.', $messageResponse->prescription->reason);

        // 7. Verify serialized response contains prescription fields
        $arrayData = $messageResponse->toArray();
        $this->assertArrayHasKey('prescription', $arrayData);
        $this->assertEquals(999, $arrayData['prescription']['content_id']);
        $this->assertEquals('Settle Your Mind', $arrayData['prescription']['title']);
        $this->assertEquals('This will help ground your racing thoughts.', $arrayData['prescription']['reason']);
    }
}
