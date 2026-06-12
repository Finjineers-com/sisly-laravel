<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Coaches;

use PHPUnit\Framework\TestCase;
use Sisly\Coaches\CredentialQuestionDetector;

class CredentialQuestionDetectorTest extends TestCase
{
    private CredentialQuestionDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new CredentialQuestionDetector();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function positiveEnglishProvider(): array
    {
        return [
            'are you a therapist'         => ["Are you a therapist?"],
            'are you a real therapist'    => ["Are you a real therapist?"],
            'are you a psychologist'      => ["Are you a psychologist?"],
            'are you a psychiatrist'      => ["Are you a psychiatrist?"],
            'are you a doctor'            => ["Are you a doctor?"],
            'are you a counselor'         => ["Are you a counselor?"],
            'are you a counsellor (UK)'   => ["Are you a counsellor?"],
            'are you a clinician'         => ["Are you a clinician?"],
            'are you a shrink'            => ["are you a shrink"],
            'are you human'               => ["Are you human?"],
            'are you a human'             => ["Are you a human?"],
            'are you real'                => ["Are you real?"],
            'are you a real person'       => ["Are you a real person?"],
            'are you a real human'        => ["Are you a real human?"],
            'are you ai'                  => ["Are you AI?"],
            'are you a bot'               => ["Are you a bot?"],
            'are you a robot'             => ["Are you a robot?"],
            'are you a chatbot'           => ["Are you a chatbot?"],
            'is this real'                => ["Is this real?"],
            'is this ai'                  => ["Is this AI?"],
            'is this a human'             => ["Is this a human?"],
            'is this a bot'               => ["Is this a bot?"],
            'am i talking to a human'     => ["Am I talking to a human?"],
            'am i talking to a real person' => ["Am I talking to a real person?"],
            'am i speaking with a therapist' => ["Am I speaking with a therapist?"],
            'are you licensed'            => ["Are you licensed?"],
            'are you qualified'           => ["Are you qualified?"],
            'do you have a license'       => ["Do you have a license?"],
            'do you have a phd'           => ["Do you have a phd?"],
            'no question mark'            => ["are you human"],
            'lowercase'                   => ["are you a therapist"],
            'extra whitespace'            => ["   Are you a therapist?   "],
        ];
    }

    /**
     * @dataProvider positiveEnglishProvider
     */
    public function test_detects_english_credential_questions(string $message): void
    {
        $this->assertTrue(
            $this->detector->isCredentialQuestion($message),
            "Expected to detect credential question in: '{$message}'"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function positiveArabicProvider(): array
    {
        return [
            'inta doctor'             => ["انت دكتور"],
            'inta doctora'            => ["انت دكتورة؟"],
            'anta doctor (hamza)'     => ["أنت دكتورة"],
            'inta mu3alij'            => ["انت معالج"],
            'inta mu3alija'           => ["انت معالجة"],
            'inta tabeeb'             => ["انت طبيب"],
            'inta tabeeba'            => ["انت طبيبة؟"],
            'inta haqiqi'             => ["انت حقيقي"],
            'inta haqiqia'            => ["انت حقيقية"],
            'hal inta haqiqia'        => ["هل انت حقيقية؟"],
            'inta bashar'             => ["انت بشر"],
            'inta insan'              => ["انت انسان"],
            'inta robot'              => ["انت روبوت"],
            'inta thaka istina3i'     => ["انت ذكاء اصطناعي"],
            'hal inta ai'             => ["هل انت ذكاء اصطناعي؟"],
        ];
    }

    /**
     * @dataProvider positiveArabicProvider
     */
    public function test_detects_arabic_credential_questions(string $message): void
    {
        $this->assertTrue(
            $this->detector->isCredentialQuestion($message),
            "Expected to detect Arabic credential question in: '{$message}'"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function negativeProvider(): array
    {
        return [
            'empty string'                              => [""],
            'whitespace only'                           => ["   "],
            'identity question (handled separately)'    => ["What is your name?"],
            'name question AR'                          => ["ما اسمك؟"],
            'normal coaching message'                   => ["I have a presentation in 20 minutes and I feel sick."],
            'vulnerability statement'                   => ["I don't feel like a real person right now."],
            'metaphor about realness'                   => ["This pain feels so real."],
            'asking about the user, not bot'            => ["Am I a real friend to her?"],
            'venting'                                   => ["My boss took credit for my work and I am furious."],
            'long message containing trigger words'     => ["I have been wondering whether I should see a therapist or maybe a doctor about this anxiety I have been feeling at work for months now."],
            'arabic about another person'               => ["أمي دكتورة وأبي معالج"],
            'genuine clinical question, not about bot'  => ["Should I see a therapist?"],
        ];
    }

    /**
     * @dataProvider negativeProvider
     */
    public function test_does_not_detect_non_credential_messages(string $message): void
    {
        $this->assertFalse(
            $this->detector->isCredentialQuestion($message),
            "Expected NOT to detect credential question in: '{$message}'"
        );
    }
}
