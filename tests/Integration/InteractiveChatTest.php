<?php

declare(strict_types=1);

namespace Sisly\Tests\Integration;

use Sisly\Facades\Sisly;
use Sisly\Enums\CoachId;
use Sisly\DTOs\SessionPreferences;
use Sisly\DTOs\GeoContext;

class InteractiveChatTest extends IntegrationTestCase
{
    /**
     * Run interactive chat from the command line.
     *
     * Run this using:
     *   vendor/bin/phpunit --no-coverage --testsuite Integration --filter test_chat
     */
    public function test_chat(): void
    {
        // Check if we are running in an interactive terminal
        if (!posix_isatty(STDIN)) {
            $this->markTestSkipped('This test requires an interactive terminal (TTY) to run.');
        }

        // Configure environment
        $this->configureRealLLM();

        // Welcome banner
        $this->write("\n\033[1;36m==================================================\033[0m\n");
        $this->write("\033[1;32m      Welcome to Sisly AI Emotional Coaching      \033[0m\n");
        $this->write("\033[1;36m==================================================\033[0m\n");
        $this->write("Active LLM Driver: \033[1;33m" . config('sisly.llm.driver') . "\033[0m\n");
        $this->write("Active Model:      \033[1;33m" . config('sisly.llm.' . config('sisly.llm.driver') . '.model') . "\033[0m\n");
        $this->write("--------------------------------------------------\n\n");

        // Let user choose a coach
        $this->write("Select an emotional support coach:\n");
        $this->write("  \033[1;32m1\033[0m) Meetly  - Anxiety, panic, and presentation fear\n");
        $this->write("  \033[1;32m2\033[0m) Vento   - Anger, frustration, and quick venting\n");
        $this->write("  \033[1;32m3\033[0m) Loopy   - Overthinking, replaying conversations\n");
        $this->write("  \033[1;32m4\033[0m) Presso  - Overwhelm, acute stress, and deadlines\n");
        $this->write("  \033[1;32m5\033[0m) Boostly - Self-doubt, confidence booster\n");
        $this->write("  \033[1;32m6\033[0m) Safeo   - General anxiety, grounding, and sleep\n");
        $this->write("\nEnter choice (1-6) [default: 1]: ");

        $choice = trim(fgets(STDIN));
        $coachMap = [
            '1' => 'meetly',
            '2' => 'vento',
            '3' => 'loopy',
            '4' => 'presso',
            '5' => 'boostly',
            '6' => 'safeo',
        ];
        $coachIdVal = $coachMap[$choice] ?? 'meetly';

        $this->write("\nInitializing session with \033[1;32m" . ucfirst($coachIdVal) . "Coach\033[0m...\n");

        // Select language
        $this->write("Select language (en / ar) [default: en]: ");
        $lang = trim(fgets(STDIN));
        $lang = in_array(strtolower($lang), ['ar', 'arabic'], true) ? 'ar' : 'en';

        // Start session
        $response = Sisly::initSession([
            'coach_id' => $coachIdVal,
            'geo' => new GeoContext(country: 'AE'),
            'preferences' => new SessionPreferences(
                language: $lang,
                arabicMirror: ($lang === 'en'),
                includeCoETrace: false
            )
        ]);

        $sessionId = $response->sessionId;
        
        $this->write("\nSession Started. Type \033[1;31mexit\033[0m or \033[1;31mquit\033[0m to end the conversation.\n");
        $this->write("--------------------------------------------------\n");

        // Print initial greeting
        $this->printCoachReply($response);

        while (true) {
            $this->write("\n\033[1;34mYou > \033[0m");
            $userInput = trim(fgets(STDIN));

            if (strtolower($userInput) === 'exit' || strtolower($userInput) === 'quit' || $userInput === '') {
                break;
            }

            $this->write("\033[0;90m[Thinking...]\033[0m\r");

            try {
                $response = Sisly::message($sessionId, $userInput);
                $this->printCoachReply($response);

                if ($response->sessionComplete) {
                    $this->write("\n\033[1;33m[Coach marked this session as complete. Wrapping up...]\033[0m\n");
                    break;
                }
            } catch (\Throwable $e) {
                $this->write("\n\033[1;31mError: " . $e->getMessage() . "\033[0m\n");
            }
        }

        $this->write("\nEnding session...\n");
        Sisly::endSession($sessionId);
        $this->write("\033[1;32mSession ended. Thank you for using Sisly!\033[0m\n\n");

        $this->assertTrue(true);
    }

    private function write(string $text): void
    {
        fwrite(STDERR, $text);
    }

    private function printCoachReply($response): void
    {
        $coachName = $response->coachName;
        $state = strtoupper($response->state->value);

        $this->write("\n\033[1;32m{$coachName} [{$state}] > \033[0m" . $response->responseText . "\n");

        if ($response->arabicMirror) {
            $this->write("\033[0;32mArabic Mirror > \033[0m" . $response->arabicMirror . "\n");
        }

        // Print prescription if resolved
        if ($response->prescription) {
            $p = $response->prescription;
            $this->write("\n\033[1;35m┌────────────────────────────────────────────────────────┐\033[0m\n");
            $this->write("\033[1;35m│ ★ RECOMMENDATION PRESCRIBED: " . str_pad(strtoupper($p->mediaCategory), 25) . " │\033[0m\n");
            $this->write("\033[1;35m├────────────────────────────────────────────────────────┤\033[0m\n");
            $this->write("\033[1;35m│ Title:       \033[0m" . str_pad($p->title, 42) . " \033[1;35m│\033[0m\n");
            $this->write("\033[1;35m│ Description: \033[0m" . str_pad(substr($p->description, 0, 42), 42) . " \033[1;35m│\033[0m\n");
            $this->write("\033[1;35m│ Duration:    \033[0m" . str_pad($p->duration . " mins", 42) . " \033[1;35m│\033[0m\n");
            $this->write("\033[1;35m│ Audio:       \033[0m" . str_pad(substr($p->audioPath, 0, 42), 42) . " \033[1;35m│\033[0m\n");
            $this->write("\033[1;35m│ Reason:      \033[0m" . str_pad(substr($p->reason, 0, 42), 42) . " \033[1;35m│\033[0m\n");
            $this->write("\033[1;35m└────────────────────────────────────────────────────────┘\033[0m\n");
        }
    }
}
