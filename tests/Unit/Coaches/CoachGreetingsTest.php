<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Coaches;

use PHPUnit\Framework\TestCase;
use Sisly\Coaches\BoostlyCoach;
use Sisly\Coaches\LoopyCoach;
use Sisly\Coaches\MeetlyCoach;
use Sisly\Coaches\PressoCoach;
use Sisly\Coaches\SafeoCoach;
use Sisly\Coaches\VentoCoach;
use Sisly\Coaches\BaseCoach;
use Sisly\Contracts\CoachInterface;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\LLM\MockProvider;

class CoachGreetingsTest extends TestCase
{
    private MockProvider $mockProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockProvider = new MockProvider();
    }

    /**
     * Create a minimal BaseCoach stub with controlled greetings.
     */
    private function createBaseCoachStub(array $greetings): BaseCoach
    {
        return new class($this->mockProvider, $greetings) extends BaseCoach {
            public function __construct(
                \Sisly\Contracts\LLMProviderInterface $llm,
                private readonly array $stubbedGreetings,
            ) {
                parent::__construct($llm);
            }

            public function getId(): CoachId { return CoachId::MEETLY; }
            public function getName(): string { return 'STUB'; }
            public function getDescription(): string { return 'Stub coach'; }
            public function getSystemPrompt(SessionState $state): string { return ''; }
            public function getStatePrompt(SessionState $state): string { return ''; }
            public function getDomains(): array { return []; }
            public function getTriggers(): array { return []; }
            public function getGreetings(): array { return $this->stubbedGreetings; }
            public function getRoleDescription(string $language): string { return 'stub role'; }
        };
    }

    public function test_base_coach_get_greeting_selects_from_greetings_array(): void
    {
        $greetings = [
            ['en' => 'Hello EN 1', 'ar' => 'مرحبا 1'],
            ['en' => 'Hello EN 2', 'ar' => 'مرحبا 2'],
            ['en' => 'Hello EN 3', 'ar' => 'مرحبا 3'],
        ];
        $coach = $this->createBaseCoachStub($greetings);

        $this->assertContains($coach->getGreeting('en'), ['Hello EN 1', 'Hello EN 2', 'Hello EN 3']);
        $this->assertContains($coach->getGreeting('ar'), ['مرحبا 1', 'مرحبا 2', 'مرحبا 3']);
    }

    public function test_base_coach_get_greeting_falls_back_to_en_for_missing_language(): void
    {
        $coach = $this->createBaseCoachStub([
            ['en' => 'Only English', 'ar' => 'عربي'],
        ]);

        $result = $coach->getGreeting('fr');
        $this->assertEquals('Only English', $result);
    }

    public function test_base_coach_get_greeting_defaults_to_en_with_no_argument(): void
    {
        $coach = $this->createBaseCoachStub([
            ['en' => 'Default EN', 'ar' => 'عربي'],
        ]);

        $this->assertEquals('Default EN', $coach->getGreeting());
    }

    public function test_base_coach_get_greeting_with_single_pair_always_returns_same(): void
    {
        $coach = $this->createBaseCoachStub([
            ['en' => 'Only one', 'ar' => 'واحد فقط'],
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->assertEquals('Only one', $coach->getGreeting('en'));
            $this->assertEquals('واحد فقط', $coach->getGreeting('ar'));
        }
    }

    /**
     * @return array<string, array{CoachInterface}>
     */
    public static function coachProvider(): array
    {
        $mock = new MockProvider();

        return [
            'meetly' => [new MeetlyCoach($mock)],
            'presso' => [new PressoCoach($mock)],
            'vento' => [new VentoCoach($mock)],
            'loopy' => [new LoopyCoach($mock)],
            'boostly' => [new BoostlyCoach($mock)],
            'safeo' => [new SafeoCoach($mock)],
        ];
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_get_greetings_returns_exactly_five_pairs(CoachInterface $coach): void
    {
        $greetings = $coach->getGreetings();

        $this->assertCount(5, $greetings);
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_each_greeting_has_en_and_ar_keys(CoachInterface $coach): void
    {
        foreach ($coach->getGreetings() as $i => $pair) {
            $this->assertArrayHasKey('en', $pair, "Greeting #{$i} missing 'en' key");
            $this->assertArrayHasKey('ar', $pair, "Greeting #{$i} missing 'ar' key");
            $this->assertNotEmpty($pair['en'], "Greeting #{$i} has empty 'en' value");
            $this->assertNotEmpty($pair['ar'], "Greeting #{$i} has empty 'ar' value");
        }
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_get_greeting_en_returns_one_of_the_five(CoachInterface $coach): void
    {
        $validEnGreetings = array_column($coach->getGreetings(), 'en');
        $greeting = $coach->getGreeting('en');

        $this->assertContains($greeting, $validEnGreetings);
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_get_greeting_ar_returns_one_of_the_five(CoachInterface $coach): void
    {
        $validArGreetings = array_column($coach->getGreetings(), 'ar');
        $greeting = $coach->getGreeting('ar');

        $this->assertContains($greeting, $validArGreetings);
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_get_greeting_defaults_to_english(CoachInterface $coach): void
    {
        $validEnGreetings = array_column($coach->getGreetings(), 'en');
        $greeting = $coach->getGreeting();

        $this->assertContains($greeting, $validEnGreetings);
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_get_greeting_falls_back_to_english_for_unknown_language(CoachInterface $coach): void
    {
        $validEnGreetings = array_column($coach->getGreetings(), 'en');
        $greeting = $coach->getGreeting('fr');

        $this->assertContains($greeting, $validEnGreetings);
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_multiple_calls_produce_randomization(CoachInterface $coach): void
    {
        $results = [];
        for ($i = 0; $i < 50; $i++) {
            $results[] = $coach->getGreeting('en');
        }

        $unique = array_unique($results);

        // With 5 options and 50 calls, we should see more than 1 unique result
        $this->assertGreaterThan(1, count($unique), 'Expected randomization across 50 calls');
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_arabic_greetings_contain_arabic_characters(CoachInterface $coach): void
    {
        foreach ($coach->getGreetings() as $pair) {
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $pair['ar']);
        }
    }
}
