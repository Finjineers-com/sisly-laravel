<?php

declare(strict_types=1);

namespace Sisly\Enums;

enum CoachId: string
{
    case MEETLY  = 'meetly';
    case VENTO   = 'vento';
    case LOOPY   = 'loopy';
    case PRESSO  = 'presso';
    case BOOSTLY = 'boostly';
    case SAFEO   = 'safeo';

    /**
     * Get the coach's display name (always Latin script — brand identifier).
     */
    public function displayName(): string
    {
        return match ($this) {
            self::MEETLY  => 'MEETLY',
            self::VENTO   => 'VENTO',
            self::LOOPY   => 'LOOPY',
            self::PRESSO  => 'PRESSO',
            self::BOOSTLY => 'BOOSTLY',
            self::SAFEO   => 'SAFEO',
        };
    }

    /**
     * Get the coach's emoji icon.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::MEETLY  => '📅',
            self::VENTO   => '💬',
            self::LOOPY   => '🧠',
            self::PRESSO  => '⏳',
            self::BOOSTLY => '⚡',
            self::SAFEO   => '🧭',
        };
    }

    /**
     * Get the coach's focus area description (English).
     */
    public function focus(): string
    {
        return match ($this) {
            self::MEETLY  => 'Meeting and presentation anxiety',
            self::VENTO   => 'Anger, frustration and venting',
            self::LOOPY   => 'Rumination and overthinking',
            self::PRESSO  => 'Work pressure and overwhelm',
            self::BOOSTLY => 'Self-doubt and imposter feelings',
            self::SAFEO   => 'Uncertainty, regional tension, and big decisions',
        };
    }

    /**
     * Get the primed opening (Phase 1, no model call) in English.
     * These are sent client-side before any backend call.
     */
    public function primedOpeningEn(): string
    {
        return match ($this) {
            self::MEETLY  => "Hi, I'm Meetly. Big meeting on your mind? Let's get you steady. Is it coming up, or did it just happen?",
            self::VENTO   => "Hi, I'm Vento. Sometimes you just need to get it out. I'm listening, no judgement. What happened?",
            self::LOOPY   => "Hi, I'm Loopy. When the mind won't stop spinning, we can slow it together. What keeps going round for you?",
            self::PRESSO  => "Hey, I'm Presso. When it's all too much at once, it helps to slow right down. What's piling up on you?",
            self::BOOSTLY => "Hey, I'm Boostly. Running on empty? Let's find you a little lift. What's draining you today?",
            self::SAFEO   => "Hi, I'm Safeo. When things feel uncertain, sometimes just talking helps. What's weighing on you?",
        };
    }

    /**
     * Get the primed opening in Arabic (Gulf dialect / Khaleeji).
     * PLACEHOLDER — must be authored natively by a GCC Arabic copywriter.
     */
    public function primedOpeningAr(): string
    {
        return match ($this) {
            self::MEETLY  => "مرحباً، أنا Meetly. عندك اجتماع مهم؟ خلني أساعدك تستقر. هل هو قادم أو صار الحين؟",
            self::VENTO   => "هلا، أنا Vento. أحياناً بس تحتاج تطلع اللي في صدرك. أنا سامعك، بدون أحكام. شو صار؟",
            self::LOOPY   => "هلا، أنا Loopy. لما الذهن ما يوقف يدور، نقدر نبطّئه مع بعض. شو اللي يدور في بالك؟",
            self::PRESSO  => "هلا، أنا Presso. لما كل شي يضغط مرة وحدة، يساعد نتنفس ونبطّئ. شو اللي يثقل عليك؟",
            self::BOOSTLY => "هلا، أنا Boostly. تحس بطاقتك نازلة؟ خلني أساعدك ترجع ثقتك. شو اللي يعبك اليوم؟",
            self::SAFEO   => "هلا، أنا Safeo. لما الأمور تحس فيها بعدم يقين، أحياناً الحكي يساعد. شو اللي يثقل عليك؟",
        };
    }

    /**
     * Get all coach IDs as an array of strings.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
