<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Coaches;

use PHPUnit\Framework\TestCase;
use Sisly\Coaches\IdentityQuestionDetector;

class IdentityQuestionDetectorTest extends TestCase
{
    private IdentityQuestionDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new IdentityQuestionDetector();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function positiveEnglishProvider(): array
    {
        return [
            'what is your name'          => ["What is your name?"],
            "what's your name"           => ["What's your name?"],
            'who are you'                => ["Who are you?"],
            'what are you'               => ["What are you?"],
            'tell me your name'          => ["Tell me your name."],
            'your name?'                 => ["your name?"],
            'what should i call you'     => ["What should I call you?"],
            'do you have a name'         => ["Do you have a name?"],
            'introduce yourself'         => ["introduce yourself"],
            'lowercase variant'          => ["what is your name"],
            'no question mark'           => ["who are you"],
            'with extra whitespace'      => ["   What's your name?   "],
        ];
    }

    /**
     * @dataProvider positiveEnglishProvider
     */
    public function test_detects_english_identity_questions(string $message): void
    {
        $this->assertTrue(
            $this->detector->isIdentityQuestion($message),
            "Expected to detect identity question in: '{$message}'"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function positiveArabicProvider(): array
    {
        return [
            'ma ismik (formal)'      => ["ما اسمك؟"],
            'ma ismik (no q-mark)'   => ["ما اسمك"],
            'shu ismik (Levantine)'  => ["شو اسمك؟"],
            'aysh ismik (Gulf)'      => ["ايش اسمك"],
            'wash ismik (Saudi)'     => ["وش اسمك؟"],
            'min anta'               => ["من انت"],
            'min anta with hamza'    => ["من أنت"],
            'meen inta'              => ["مين انت"],
            'inta meen'              => ["انت مين؟"],
            'introduce yourself AR'  => ["عرفني بنفسك"],
        ];
    }

    /**
     * @dataProvider positiveArabicProvider
     */
    public function test_detects_arabic_identity_questions(string $message): void
    {
        $this->assertTrue(
            $this->detector->isIdentityQuestion($message),
            "Expected to detect Arabic identity question in: '{$message}'"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function negativeProvider(): array
    {
        return [
            'empty string'                          => [""],
            'whitespace only'                       => ["   "],
            'vulnerability statement EN'            => ["I don't know who I am right now."],
            'vague help request'                    => ["I need help, who can I talk to?"],
            'wondering about another'               => ["Who is going to help me with this?"],
            'name of a place AR'                    => ["ما اسم هذا المكان؟"],
            'long EN message containing your name'  => ["I was wondering if you could tell me what your name might be in a context where I am asking many things at once because I'm overwhelmed."],
            'normal coaching message'               => ["I have a presentation in 20 minutes and I feel sick."],
            'normal coaching message AR'            => ["عندي عرض تقديمي بعد ٢٠ دقيقة وأشعر بالغثيان."],
            'philosophical question'                => ["What is going to happen tomorrow?"],
            'venting'                               => ["My boss took credit for my work and I am furious."],
        ];
    }

    /**
     * @dataProvider negativeProvider
     */
    public function test_does_not_detect_non_identity_messages(string $message): void
    {
        $this->assertFalse(
            $this->detector->isIdentityQuestion($message),
            "Expected NOT to detect identity question in: '{$message}'"
        );
    }
}
