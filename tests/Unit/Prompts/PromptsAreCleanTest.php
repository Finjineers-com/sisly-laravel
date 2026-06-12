<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Prompts;

use PHPUnit\Framework\TestCase;
use Sisly\Coaches\PromptLoader;
use Sisly\Enums\CoachId;

/**
 * Regression guard: ensures the dead "Arabic Mirror" prompt sections
 * stay deleted. Language behavior is now controlled at runtime by
 * BaseCoach::buildLanguageRule(); embedded "include Arabic mirror"
 * directives in the prompts conflict with that override and must not
 * be reintroduced.
 */
class PromptsAreCleanTest extends TestCase
{
    private PromptLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = new PromptLoader();
    }

    public function test_global_rules_does_not_contain_arabic_mirror_section(): void
    {
        $rules = $this->loader->loadGlobal('rules');

        $this->assertStringNotContainsString('## Arabic Mirror', $rules);
        $this->assertStringNotContainsString('Arabic mirror if first response', $rules);
        $this->assertStringNotContainsString('Include one short Arabic validation line in first response', $rules);
    }

    /**
     * @return array<string, array{0: CoachId}>
     */
    public static function coachProvider(): array
    {
        return [
            'MEETLY'  => [CoachId::MEETLY],
            'VENTO'   => [CoachId::VENTO],
            'LOOPY'   => [CoachId::LOOPY],
            'PRESSO'  => [CoachId::PRESSO],
            'BOOSTLY' => [CoachId::BOOSTLY],
            'SAFEO'   => [CoachId::SAFEO],
        ];
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_coach_system_prompt_does_not_contain_arabic_mirror_examples(CoachId $coachId): void
    {
        $prompt = $this->loader->loadCoachSystem($coachId);

        $this->assertStringNotContainsString('## Arabic Mirror Examples', $prompt);
        $this->assertStringNotContainsString('First response should include one of:', $prompt);
    }

    /**
     * Regression guard for the credential reframe in M1+M2: enriched coach
     * personas must NEVER assert clinical credentials. The persona is
     * informed by long experience, but the prompt must explicitly disclaim
     * being a clinician.
     *
     * @dataProvider coachProvider
     */
    public function test_coach_system_prompt_does_not_claim_clinical_credentials(CoachId $coachId): void
    {
        $prompt = strtolower($this->loader->loadCoachSystem($coachId));

        // Hard-banned literal claims (case-insensitive). The prompt may
        // *disclaim* these (e.g., "not a psychologist") — that's why we
        // search for first-person assertions rather than the bare word.
        $forbidden = [
            '30 years as a psychologist',
            '30 years experience as a psychologist',
            '10,000 hours of corporate counselling',
            'i am a psychologist',
            'i am a therapist',
            'i am a doctor',
            'i am a clinician',
            'i am a psychiatrist',
            'years of clinical experience',
        ];

        foreach ($forbidden as $term) {
            $this->assertStringNotContainsString(
                $term,
                $prompt,
                "{$coachId->value}'s system prompt must not contain credential claim: '{$term}'"
            );
        }
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_coach_system_prompt_disclaims_being_a_clinician(CoachId $coachId): void
    {
        $prompt = $this->loader->loadCoachSystem($coachId);

        $this->assertStringContainsString(
            'AI coach',
            $prompt,
            "{$coachId->value}'s system prompt must explicitly identify as an AI coach"
        );
    }
}
