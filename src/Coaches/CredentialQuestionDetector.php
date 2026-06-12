<?php

declare(strict_types=1);

namespace Sisly\Coaches;

/**
 * Detects whether a user message is a direct question about the bot's
 * credentials or human-ness ("are you a therapist?", "are you human?",
 * "هل انت حقيقية؟").
 *
 * Used by BaseCoach to short-circuit such questions with a deterministic
 * reply that disclaims any clinical credential — bypassing the LLM so the
 * model cannot drift into claiming to be a psychologist, doctor, or human.
 *
 * Patterns are deliberately tight (anchored / short-message-only) to avoid
 * false positives on phrases like "you are real to me" inside vulnerability
 * statements.
 */
class CredentialQuestionDetector
{
    /**
     * Maximum trimmed-message length (chars) for a message to be considered
     * a credential question. Prevents long messages that happen to contain
     * trigger phrases from being misclassified.
     */
    private const MAX_LENGTH = 80;

    /**
     * English patterns. Anchored to the entire (trimmed) message so a
     * pattern only fires when the message IS the question.
     *
     * @var array<string>
     */
    private const EN_PATTERNS = [
        '/^(?:are\s+you\s+(?:a\s+)?(?:real\s+)?(?:therapist|psychologist|psychiatrist|counselor|counsellor|clinician|doctor|shrink))\s*\??\s*$/i',
        '/^(?:are\s+you\s+(?:an?\s+)?(?:human|person|real|ai|bot|robot|chatbot|machine))\s*\??\s*$/i',
        '/^(?:is\s+this\s+(?:an?\s+)?(?:real|ai|human|bot|machine))\s*\??\s*$/i',
        '/^(?:am\s+i\s+(?:talking|speaking|chatting)\s+(?:to|with)\s+(?:an?\s+)?(?:human|person|real\s+person|real\s+therapist|therapist|psychologist|doctor|ai|bot|robot|machine))\s*\??\s*$/i',
        '/^(?:do\s+you\s+have\s+(?:a\s+)?(?:license|qualification|degree|phd|credential|credentials))\s*\??\s*$/i',
        '/^(?:are\s+you\s+licensed)\s*\??\s*$/i',
        '/^(?:are\s+you\s+qualified)\s*\??\s*$/i',
        '/^(?:are\s+you\s+a\s+real\s+(?:person|human))\s*\??\s*$/i',
    ];

    /**
     * Arabic patterns. Matched as substrings within short messages because
     * Arabic phrasing varies more freely. The MAX_LENGTH cap above prevents
     * false positives in long text.
     *
     * @var array<string>
     */
    private const AR_PATTERNS = [
        '/(انت\s+دكتور)/u',
        '/(أنت\s+دكتور)/u',
        '/(انت\s+دكتورة)/u',
        '/(أنت\s+دكتورة)/u',
        '/(انت\s+معالج)/u',
        '/(أنت\s+معالج)/u',
        '/(انت\s+طبيب)/u',
        '/(أنت\s+طبيب)/u',
        '/(انت\s+طبيبة)/u',
        '/(انت\s+حقيقي)/u',
        '/(أنت\s+حقيقي)/u',
        '/(انت\s+حقيقية)/u',
        '/(أنت\s+حقيقية)/u',
        '/(هل\s+انت\s+حقيقية)/u',
        '/(هل\s+أنت\s+حقيقية)/u',
        '/(انت\s+بشر)/u',
        '/(أنت\s+بشر)/u',
        '/(انت\s+انسان)/u',
        '/(أنت\s+إنسان)/u',
        '/(انت\s+روبوت)/u',
        '/(أنت\s+روبوت)/u',
        '/(انت\s+ذكاء\s+اصطناعي)/u',
        '/(أنت\s+ذكاء\s+اصطناعي)/u',
        '/(هل\s+انت\s+ذكاء\s+اصطناعي)/u',
    ];

    public function isCredentialQuestion(string $message): bool
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
