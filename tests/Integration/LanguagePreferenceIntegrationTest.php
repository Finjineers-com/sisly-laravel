<?php

declare(strict_types=1);

namespace Sisly\Tests\Integration;

use Sisly\Arabic\LanguageDetector;
use Sisly\Coaches\BoostlyCoach;
use Sisly\Coaches\LoopyCoach;
use Sisly\Coaches\MeetlyCoach;
use Sisly\Coaches\PressoCoach;
use Sisly\Coaches\SafeoCoach;
use Sisly\Coaches\VentoCoach;
use Sisly\Contracts\CoachInterface;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;
use Sisly\LLM\Providers\OpenAIProvider;

/**
 * Live-LLM verification that SessionPreferences.language and
 * SessionPreferences.arabicMirror flags are actually honored by every coach.
 *
 * Regression guard for: "Coaches reply in Arabic even when not requested.
 * The arabicMirror flag is asked for but not respected."
 *
 * Hits real OpenAI. Requires OPENAI_API_KEY in .env.testing.
 * Run with: ./vendor/bin/phpunit --testsuite Integration --filter LanguagePreferenceIntegrationTest
 */
class LanguagePreferenceIntegrationTest extends IntegrationTestCase
{
    /**
     * Coaching messages chosen so each coach naturally engages its domain
     * (no identity questions — those have a separate test).
     *
     * @var array<string, array{en: string, ar: string}>
     */
    private const COACHING_MESSAGES = [
        'MEETLY'  => ['en' => 'I have a presentation in 20 minutes and I feel sick.',  'ar' => 'عندي عرض تقديمي بعد ٢٠ دقيقة وأشعر بالغثيان.'],
        'VENTO'   => ['en' => 'My boss took credit for my work and I am furious.',     'ar' => 'مديري أخذ الفضل في عملي وأنا غاضب جداً.'],
        'LOOPY'   => ['en' => 'I keep replaying what I said in the meeting yesterday.', 'ar' => 'أعيد التفكير فيما قلته في الاجتماع أمس.'],
        'PRESSO'  => ['en' => 'I have five deadlines today and cannot start anything.', 'ar' => 'عندي خمسة مواعيد نهائية اليوم ولا أستطيع البدء بأي شيء.'],
        'BOOSTLY' => ['en' => 'Everyone here is smarter than me. I do not belong.',     'ar' => 'كل من هنا أذكى مني. لا أنتمي إلى هذا المكان.'],
        'SAFEO'   => ['en' => 'I do not know what is going to happen with the layoffs and I cannot stop worrying about the future.',
                      'ar' => 'ما أدري شو راح يصير مع تقليص الموظفين وما أقدر أوقف القلق على المستقبل.'],
    ];

    public function test_arabic_mirror_disabled_produces_zero_arabic_characters(): void
    {
        $this->requireOpenAI();

        $detector = new LanguageDetector();
        $failures = [];

        foreach ($this->buildCoaches() as $coachName => $coach) {
            $message = self::COACHING_MESSAGES[$coachName]['en'];
            $response = $this->askCoach(
                coach: $coach,
                coachId: CoachId::from(strtolower($coachName)),
                preferences: new SessionPreferences(language: 'en', arabicMirror: false),
                message: $message,
            );

            $arabicCount = $detector->countArabicCharacters($response);

            if ($arabicCount > 0) {
                $failures[$coachName] = [
                    'arabic_chars' => $arabicCount,
                    'response' => $response,
                ];
            }
        }

        $this->assertEmpty(
            $failures,
            "When arabicMirror=false and language=en, responses must contain ZERO Arabic characters:\n" . $this->formatFailures($failures)
        );
    }

    public function test_language_arabic_produces_no_english_words_in_body(): void
    {
        $this->requireOpenAI();

        $failures = [];

        foreach ($this->buildCoaches() as $coachName => $coach) {
            $message = self::COACHING_MESSAGES[$coachName]['ar'];
            $response = $this->askCoach(
                coach: $coach,
                coachId: CoachId::from(strtolower($coachName)),
                preferences: new SessionPreferences(language: 'ar'),
                message: $message,
            );

            // The coach name (Latin letters) is allowed; everything else must be non-Latin.
            $bodyWithoutCoachName = str_ireplace($coachName, '', $response);
            $latinWordCount = preg_match_all('/\b[a-zA-Z]{3,}\b/u', $bodyWithoutCoachName);

            if ($latinWordCount > 0) {
                $failures[$coachName] = [
                    'latin_words' => $latinWordCount,
                    'response' => $response,
                ];
            }
        }

        $this->assertEmpty(
            $failures,
            "When language=ar, response body should not contain English words (coach name itself is allowed):\n" . $this->formatFailures($failures)
        );
    }

    /**
     * @return array<string, CoachInterface>
     */
    private function buildCoaches(): array
    {
        $llm = $this->buildLiveProvider();

        return [
            'MEETLY'  => new MeetlyCoach($llm),
            'VENTO'   => new VentoCoach($llm),
            'LOOPY'   => new LoopyCoach($llm),
            'PRESSO'  => new PressoCoach($llm),
            'BOOSTLY' => new BoostlyCoach($llm),
            'SAFEO'   => new SafeoCoach($llm),
        ];
    }

    private function buildLiveProvider(): LLMProviderInterface
    {
        return new OpenAIProvider([
            'api_key' => $this->openaiApiKey,
            'model'   => 'gpt-4-turbo',
            'timeout' => 30,
        ]);
    }

    private function askCoach(
        CoachInterface $coach,
        CoachId $coachId,
        SessionPreferences $preferences,
        string $message,
    ): string {
        $session = Session::create(
            id: 'lang-test-' . $coachId->value . '-' . $preferences->language . '-' . ($preferences->arabicMirror ? 'm1' : 'm0'),
            coachId: $coachId,
            geo: new GeoContext('AE'),
            preferences: $preferences,
        );

        $result = $coach->process($session, $message);

        return $result['response'] ?? '';
    }

    /**
     * @param array<string, array<string, mixed>> $failures
     */
    private function formatFailures(array $failures): string
    {
        $lines = [];
        foreach ($failures as $coachName => $detail) {
            $lines[] = "  - {$coachName}: " . json_encode($detail, JSON_UNESCAPED_UNICODE);
        }
        return implode("\n", $lines);
    }
}
