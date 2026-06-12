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
use Sisly\Contracts\CoachInterface;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;
use Sisly\LLM\MockProvider;

/**
 * Verifies the deterministic identity reply produced by BaseCoach::process()
 * when an identity question is detected. Since this path bypasses the LLM,
 * we don't need a live integration test for it.
 */
class HardcodedIdentityReplyTest extends TestCase
{
    /**
     * @return array<string, array{0: CoachInterface, 1: CoachId, 2: string}>
     */
    public static function coachProvider(): array
    {
        $llm = new MockProvider();

        return [
            'MEETLY'  => [new MeetlyCoach($llm),  CoachId::MEETLY,  'MEETLY'],
            'VENTO'   => [new VentoCoach($llm),   CoachId::VENTO,   'VENTO'],
            'LOOPY'   => [new LoopyCoach($llm),   CoachId::LOOPY,   'LOOPY'],
            'PRESSO'  => [new PressoCoach($llm),  CoachId::PRESSO,  'PRESSO'],
            'BOOSTLY' => [new BoostlyCoach($llm), CoachId::BOOSTLY, 'BOOSTLY'],
            'SAFEO'   => [new SafeoCoach($llm),   CoachId::SAFEO,   'SAFEO'],
        ];
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_english_identity_reply_contains_latin_coach_name(
        CoachInterface $coach,
        CoachId $coachId,
        string $expectedName,
    ): void {
        $session = $this->makeSession($coachId, 'en');

        $result = $coach->process($session, "What is your name?");

        $this->assertStringContainsString($expectedName, $result['response']);
        $this->assertStringNotContainsString('Sisly', $result['response']);
        $this->assertNull($result['arabic_mirror']);
        $this->assertNull($result['coe_trace']);
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_arabic_identity_reply_contains_latin_coach_name(
        CoachInterface $coach,
        CoachId $coachId,
        string $expectedName,
    ): void {
        $session = $this->makeSession($coachId, 'ar');

        $result = $coach->process($session, "ما اسمك؟");

        // Coach name MUST stay in Latin script even in Arabic responses.
        $this->assertStringContainsString($expectedName, $result['response']);
        $this->assertStringNotContainsString('Sisly', $result['response']);
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_role_description_is_localized(
        CoachInterface $coach,
        CoachId $coachId,
        string $expectedName,
    ): void {
        $en = $coach->getRoleDescription('en');
        $ar = $coach->getRoleDescription('ar');

        $this->assertNotEmpty($en);
        $this->assertNotEmpty($ar);
        $this->assertNotSame($en, $ar, "{$expectedName}'s role description must differ between EN and AR.");

        // EN should be Latin-only; AR should contain Arabic chars.
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $en, "EN role description should be ASCII for {$expectedName}.");
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $ar, "AR role description should contain Arabic characters for {$expectedName}.");
    }

    public function test_identity_question_does_not_call_llm(): void
    {
        $mockLlm = new MockProvider();
        $coach = new MeetlyCoach($mockLlm);
        $session = $this->makeSession(CoachId::MEETLY, 'en');

        $result = $coach->process($session, "What is your name?");

        // MockProvider tracks calls. The deterministic reply path should never invoke chat().
        $this->assertSame(0, $mockLlm->getCallCount(), 'Identity questions must not invoke the LLM.');
        $this->assertNotEmpty($result['response']);
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_english_credential_reply_disclaims_clinical_credentials(
        CoachInterface $coach,
        CoachId $coachId,
        string $expectedName,
    ): void {
        $session = $this->makeSession($coachId, 'en');

        $result = $coach->process($session, "Are you a therapist?");

        $this->assertStringContainsString($expectedName, $result['response']);
        $this->assertStringContainsString('AI coach', $result['response']);
        $this->assertStringContainsString('not a clinician', $result['response']);

        // Hard guard: the disclaimer must NEVER assert any clinical credential.
        $forbidden = ['psychologist', 'psychiatrist', 'therapist who', 'I am a therapist',
                      'I am a doctor', 'licensed', 'qualified to diagnose'];
        foreach ($forbidden as $term) {
            $this->assertStringNotContainsStringIgnoringCase(
                $term,
                $result['response'],
                "Credential reply must not contain '{$term}'."
            );
        }

        $this->assertNull($result['arabic_mirror']);
        $this->assertNull($result['coe_trace']);
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_arabic_credential_reply_disclaims_clinical_credentials(
        CoachInterface $coach,
        CoachId $coachId,
        string $expectedName,
    ): void {
        $session = $this->makeSession($coachId, 'ar');

        $result = $coach->process($session, "هل انت حقيقية؟");

        // Coach name MUST stay in Latin script even in Arabic responses.
        $this->assertStringContainsString($expectedName, $result['response']);

        // Must contain the Arabic AI-coach phrasing.
        $this->assertStringContainsString('ذكاء اصطناعي', $result['response']);
    }

    public function test_credential_question_does_not_call_llm(): void
    {
        $mockLlm = new MockProvider();
        $coach = new MeetlyCoach($mockLlm);
        $session = $this->makeSession(CoachId::MEETLY, 'en');

        $result = $coach->process($session, "Are you human?");

        $this->assertSame(0, $mockLlm->getCallCount(), 'Credential questions must not invoke the LLM.');
        $this->assertNotEmpty($result['response']);
    }

    private function makeSession(CoachId $coachId, string $language): Session
    {
        return Session::create(
            id: 'identity-test-' . $coachId->value . '-' . $language,
            coachId: $coachId,
            geo: new GeoContext('AE'),
            preferences: new SessionPreferences(language: $language),
        );
    }
}
