<?php

declare(strict_types=1);

namespace Sisly\Coaches;

/**
 * Detects whether a user message is a direct meta-question about
 * the bot's identity (its name / who it is / what it is).
 *
 * Used by BaseCoach to short-circuit identity questions with a
 * deterministic hardcoded reply, bypassing the LLM entirely.
 *
 * Patterns are deliberately tight: they only fire on short, direct
 * questions to avoid false positives on vulnerability statements
 * like "I don't know who I am right now."
 */
class IdentityQuestionDetector
{
    /**
     * Maximum trimmed-message length (chars) for a message to be
     * considered an identity question. Prevents long messages that
     * happen to contain identity phrases from being misclassified.
     */
    private const MAX_LENGTH = 60;

    /**
     * English patterns. Anchored to the entire (trimmed) message —
     * a pattern only fires when the message IS the question, not
     * when the phrase appears inside a longer message.
     *
     * @var array<string>
     */
    private const EN_PATTERNS = [
        '/^(what(?:\'s| is)\s+your\s+name)\s*\??\s*$/i',
        '/^(what\s+is\s+your\s+name)\s*\??\s*$/i',
        '/^(who\s+are\s+you)\s*\??\s*$/i',
        '/^(what\s+are\s+you)\s*\??\s*$/i',
        '/^(tell\s+me\s+your\s+name)\s*\.?\s*$/i',
        '/^(your\s+name)\s*\??\s*$/i',
        '/^(what\s+do\s+(?:i|we)\s+call\s+you)\s*\??\s*$/i',
        '/^(what\s+should\s+i\s+call\s+you)\s*\??\s*$/i',
        '/^(can\s+(?:you\s+)?(?:tell\s+me\s+)?your\s+name)\s*\??\s*$/i',
        '/^(do\s+you\s+have\s+a\s+name)\s*\??\s*$/i',
        '/^(introduce\s+yourself)\s*\.?\s*$/i',
    ];

    /**
     * Arabic patterns. Matched as substrings within short messages
     * (no anchoring) because Arabic phrasing varies more freely.
     * The MAX_LENGTH cap above prevents false positives in long text.
     *
     * @var array<string>
     */
    private const AR_PATTERNS = [
        '/(ما\s+اسمك)/u',
        '/(شو\s+اسمك)/u',
        '/(ايش\s+اسمك)/u',
        '/(إيش\s+اسمك)/u',
        '/(وش\s+اسمك)/u',
        '/(من\s+انت)/u',
        '/(من\s+أنت)/u',
        '/(مين\s+انت)/u',
        '/(مين\s+أنت)/u',
        '/(انت\s+مين)/u',
        '/(أنت\s+مين)/u',
        '/(عرفني\s+بنفسك)/u',
        '/(عرّفني\s+بنفسك)/u',
    ];

    public function isIdentityQuestion(string $message): bool
    {
        $trimmed = trim($message);

        if ($trimmed === '') {
            return false;
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            return false;
        }

        foreach (self::EN_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed) === 1) {
                return true;
            }
        }

        foreach (self::AR_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed) === 1) {
                return true;
            }
        }

        return false;
    }
}
