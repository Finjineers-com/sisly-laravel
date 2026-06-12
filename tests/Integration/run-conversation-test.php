<?php

/**
 * Human-readable conversation test script.
 *
 * Runs full coaching conversations and outputs them to a markdown file.
 *
 * Usage: php tests/Integration/run-conversation-test.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Load .env.testing
$envFile = __DIR__ . '/../../.env.testing';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

use Sisly\LLM\Providers\OpenAIProvider;
use Sisly\Coaches\PromptLoader;
use Sisly\Coaches\MeetlyCoach;
use Sisly\Coaches\VentoCoach;
use Sisly\Coaches\LoopyCoach;
use Sisly\Coaches\PressoCoach;
use Sisly\Coaches\BoostlyCoach;
use Sisly\Coaches\SafeoCoach;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\ConversationTurn;
use Sisly\Enums\CoachId;
use Sisly\FSM\StateMachine;

$output = [];
$output[] = "# Sisly Conversation Test Results";
$output[] = "";
$output[] = "**Generated:** " . date('Y-m-d H:i:s');
$output[] = "";

// Check API key
$apiKey = getenv('OPENAI_API_KEY');
if (empty($apiKey)) {
    $output[] = "## ERROR: No API Key";
    $output[] = "Please set OPENAI_API_KEY in .env.testing";
    file_put_contents(__DIR__ . '/conversation-test-results.md', implode("\n", $output));
    echo "Error: No API key. Check conversation-test-results.md\n";
    exit(1);
}

$output[] = "**API Key:** `" . substr($apiKey, 0, 10) . "..." . substr($apiKey, -4) . "`";
$output[] = "**Model:** gpt-4-turbo";
$output[] = "";

echo "Starting conversation tests...\n";
echo "API Key: " . substr($apiKey, 0, 10) . "...\n\n";

// Initialize LLM provider
$llm = new OpenAIProvider([
    'api_key' => $apiKey,
    'model' => 'gpt-4-turbo',
    'timeout' => 60,
    'max_retries' => 2,
]);

$promptLoader = new PromptLoader();
$stateMachine = new StateMachine();

// Coach factory - maps coach IDs to their classes
$coachFactory = [
    'meetly' => fn() => new MeetlyCoach($llm, $promptLoader),
    'vento' => fn() => new VentoCoach($llm, $promptLoader),
    'loopy' => fn() => new LoopyCoach($llm, $promptLoader),
    'presso' => fn() => new PressoCoach($llm, $promptLoader),
    'boostly' => fn() => new BoostlyCoach($llm, $promptLoader),
    'safeo' => fn() => new SafeoCoach($llm, $promptLoader),
];

// Test scenarios - one per coach
$scenarios = [
    [
        'name' => 'MEETLY: Anxiety about Job Interview',
        'coach' => 'meetly',
        'coachId' => CoachId::MEETLY,
        'messages' => [
            "I've been feeling really anxious about my job interview tomorrow.",
            "I keep imagining all the ways it could go wrong.",
            "What if I blank out and can't answer their questions?",
            "I guess I could try that. What else might help?",
            "Thank you, I feel a bit better now.",
        ],
    ],
    [
        'name' => 'VENTO: Angry at Coworker',
        'coach' => 'vento',
        'coachId' => CoachId::VENTO,
        'messages' => [
            "I'm so furious at my coworker who took credit for my work in front of the whole team.",
            "He presented my entire project as if he did it himself.",
            "I have about 1 minute. What can I do with this anger?",
        ],
    ],
    [
        'name' => 'LOOPY: Replaying a Conversation',
        'coach' => 'loopy',
        'coachId' => CoachId::LOOPY,
        'messages' => [
            "I can't stop replaying a conversation I had with my manager. I keep thinking about what I should have said.",
            "It's been three days and my brain won't stop going in circles.",
            "I have about 1 minute to try something.",
        ],
    ],
    [
        'name' => 'PRESSO: Deadline Overwhelm',
        'coach' => 'presso',
        'coachId' => CoachId::PRESSO,
        'messages' => [
            "I'm completely overwhelmed. I have three projects due this week and I can't even start.",
            "Everything feels urgent and I'm frozen.",
            "Maybe 30 seconds. I just need to breathe.",
        ],
    ],
    [
        'name' => 'BOOSTLY: Imposter Syndrome After Promotion',
        'coach' => 'boostly',
        'coachId' => CoachId::BOOSTLY,
        'messages' => [
            "I just got promoted to team lead but I feel like a total fraud. Everyone else is more qualified.",
            "I keep comparing myself to my colleague who has 10 years more experience.",
            "I have about 1 minute. Can you help me feel more grounded?",
        ],
    ],
    [
        'name' => 'SAFEO: Job Insecurity and Big Decision',
        'coach' => 'safeo',
        'coachId' => CoachId::SAFEO,
        'messages' => [
            "There are layoffs coming at my company and I don't know if I should stay or start looking now.",
            "Everything feels so unsteady. I can't sleep thinking about what's coming.",
            "I have about 1 minute. I just need a moment of quiet.",
        ],
    ],
];

foreach ($scenarios as $scenarioIndex => $scenario) {
    $output[] = "---";
    $output[] = "";
    $output[] = "## " . ($scenarioIndex + 1) . ". " . $scenario['name'];
    $output[] = "";

    echo "Running scenario: " . $scenario['name'] . "\n";

    // Create session with the appropriate coach
    $coachId = $scenario['coachId'];
    $session = Session::create(
        id: 'test-' . uniqid(),
        coachId: $coachId,
        geo: new GeoContext('AE'),
        preferences: new SessionPreferences(arabicMirror: false, includeCoETrace: false),
    );

    // Use the coach specified in the scenario
    $coach = $coachFactory[$scenario['coach']]();

    foreach ($scenario['messages'] as $turnIndex => $userMessage) {
        $turnNum = $turnIndex + 1;
        $previousState = $session->state->value;

        echo "  Turn {$turnNum}: User says: \"" . substr($userMessage, 0, 50) . "...\"\n";

        // Add user message to history
        $session->addTurn(ConversationTurn::user($userMessage));

        // Process with coach
        $startTime = microtime(true);

        try {
            // First, test the LLM directly to see if it's working
            if ($turnNum === 1 && $scenarioIndex === 0) {
                echo "\n  [DEBUG] Testing LLM directly with Guzzle...\n";

                // Direct Guzzle test to OpenAI
                $guzzle = new \GuzzleHttp\Client(['timeout' => 30]);
                try {
                    $directResponse = $guzzle->post('https://api.openai.com/v1/chat/completions', [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $apiKey,
                            'Content-Type' => 'application/json',
                        ],
                        'json' => [
                            'model' => 'gpt-4-turbo',
                            'messages' => [['role' => 'user', 'content' => 'Say hello']],
                            'max_tokens' => 10,
                        ],
                        'http_errors' => false,
                    ]);
                    $statusCode = $directResponse->getStatusCode();
                    $body = json_decode($directResponse->getBody()->getContents(), true);
                    echo "  [DEBUG] Direct Guzzle - Status: {$statusCode}\n";
                    if ($statusCode === 200) {
                        echo "  [DEBUG] Direct Guzzle - Response: " . ($body['choices'][0]['message']['content'] ?? 'N/A') . "\n";
                    } else {
                        echo "  [DEBUG] Direct Guzzle - Error: " . json_encode($body['error'] ?? $body) . "\n";
                    }
                } catch (\Throwable $e) {
                    echo "  [DEBUG] Direct Guzzle - Exception: " . $e->getMessage() . "\n";
                }

                // Now test via provider
                $testResult = $llm->generate("Say hello in one word.");
                echo "  [DEBUG] Provider Test - Success: " . ($testResult->success ? 'YES' : 'NO') . "\n";
                if (!$testResult->success) {
                    echo "  [DEBUG] Provider Error: " . $testResult->error . "\n";
                } else {
                    echo "  [DEBUG] Provider Response: " . $testResult->content . "\n";
                }
                echo "\n";
            }

            $result = $coach->process($session, $userMessage);
            $response = $result['response'];
            $elapsed = round((microtime(true) - $startTime) * 1000);

            // Check if this is a fallback response
            $fallbacks = [
                "I'm here with you. Tell me what's on your mind.",
                "Can you tell me a bit more about what you're experiencing?",
                "I hear you. That makes sense.",
                "Let's try something together. Do you have 30 seconds, 1 minute, or 2 minutes?",
                "You've done well to take this time for yourself.",
                "I'm here with you.",
            ];
            $isFallback = in_array($response, $fallbacks);
            if ($isFallback) {
                echo "  [WARNING] Got fallback response - LLM call likely failed!\n";
            }

            // Add coach response to history
            $session->addTurn(ConversationTurn::assistant($response));

            // Try to advance state using FSM (stateTurns now persisted on Session)
            $stateMachine->incrementStateTurns($session);
            if ($stateMachine->shouldAdvance($session)) {
                $stateMachine->advance($session);
                // Note: advance() calls transitionTo() which resets stateTurns automatically
            }

            // Format for markdown
            $output[] = "### Turn {$turnNum}";
            $output[] = "";
            $output[] = "**State:** `{$previousState}` → `{$session->state->value}`";
            $output[] = "";
            $output[] = "**User:**";
            $output[] = "> {$userMessage}";
            $output[] = "";
            $coachName = strtoupper($scenario['coach']);
            $output[] = "**Coach ({$coachName}):** _{$elapsed}ms_";
            $output[] = "> {$response}";
            $output[] = "";

            echo "  Turn {$turnNum}: Coach replied in {$elapsed}ms\n";
            echo "  Turn {$turnNum}: State: {$previousState} → {$session->state->value}\n";

        } catch (\Throwable $e) {
            $output[] = "### Turn {$turnNum}";
            $output[] = "";
            $output[] = "**ERROR:** " . $e->getMessage();
            $output[] = "```";
            $output[] = $e->getTraceAsString();
            $output[] = "```";
            $output[] = "";

            echo "  Turn {$turnNum}: ERROR - " . $e->getMessage() . "\n";
            break;
        }

        // Small delay to avoid rate limiting
        usleep(500000); // 0.5 seconds
    }

    $output[] = "**Final State:** `{$session->state->value}`";
    $output[] = "**Total Turns:** {$session->turnCount}";
    $output[] = "";

    echo "  Final state: {$session->state->value}, Turns: {$session->turnCount}\n\n";

    // Cleanup FSM state
    $stateMachine->cleanup($session->id);
}

// Summary
$output[] = "---";
$output[] = "";
$output[] = "## Summary";
$output[] = "";
$output[] = "All conversations completed. Review the responses above to verify:";
$output[] = "";
$output[] = "1. **Conversations flow naturally** - Coach responses are contextual and empathetic";
$output[] = "2. **States change appropriately** - From intake → exploration → deepening → problem_solving → closing";
$output[] = "3. **Responses are NOT generic fallbacks** - Each response should be unique to the user's message";
$output[] = "";

// Write to file
$outputPath = __DIR__ . '/conversation-test-results.md';
file_put_contents($outputPath, implode("\n", $output));

echo "Done! Results written to: tests/Integration/conversation-test-results.md\n";
