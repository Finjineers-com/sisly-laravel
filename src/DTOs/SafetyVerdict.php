<?php

declare(strict_types=1);

namespace Sisly\DTOs;

/**
 * Verdict from the parallel LLM safety classifier.
 *
 * Spec: "You are a safety classifier for a non-clinical workplace mood app
 * used in the UAE. You do not chat. You read the latest user message (and
 * brief context) and return ONLY a JSON verdict. You err toward caution."
 *
 * verdict values:
 *   "ok"       = ordinary workday stress, no risk signal
 *   "checking" = ambiguous / worrying language, keep monitoring
 *   "flagged"  = clear crisis signal — discard coach output, show crisis resources
 */
final class SafetyVerdict
{
    public const VERDICT_OK       = 'ok';
    public const VERDICT_CHECKING = 'checking';
    public const VERDICT_FLAGGED  = 'flagged';

    public const VALID_VERDICTS = [self::VERDICT_OK, self::VERDICT_CHECKING, self::VERDICT_FLAGGED];

    public const CATEGORY_NONE            = 'none';
    public const CATEGORY_SELF_HARM       = 'self_harm';
    public const CATEGORY_HARM_TO_OTHERS  = 'harm_to_others';
    public const CATEGORY_ABUSE           = 'abuse';
    public const CATEGORY_MEDICAL         = 'medical_emergency';
    public const CATEGORY_ACUTE_DISTRESS  = 'acute_distress';

    public function __construct(
        public readonly string  $verdict,    // ok | checking | flagged
        public readonly string  $category,   // none | self_harm | harm_to_others | ...
        public readonly string  $rationale,  // one short line (for logging only)
        public readonly bool    $parseError = false, // true when classifier response couldn't be parsed
    ) {}

    /**
     * Parse a JSON string returned by the safety classifier LLM.
     *
     * Spec: "fail closed — if parsing fails, treat as 'checking'"
     */
    public static function fromJson(string $raw, string $failClosedVerdict = self::VERDICT_CHECKING): self
    {
        $text = trim(preg_replace('/```json|```/', '', $raw) ?? $raw);

        try {
            $data = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::parseFailure($failClosedVerdict);
        }

        if (!is_array($data)) {
            return self::parseFailure($failClosedVerdict);
        }

        $verdict   = $data['verdict']   ?? null;
        $category  = $data['category']  ?? self::CATEGORY_NONE;
        $rationale = $data['rationale'] ?? '';

        if (!in_array($verdict, self::VALID_VERDICTS, true)) {
            return self::parseFailure($failClosedVerdict);
        }

        return new self(
            verdict: $verdict,
            category: is_string($category) ? $category : self::CATEGORY_NONE,
            rationale: is_string($rationale) ? $rationale : '',
        );
    }

    /**
     * Create a parse-failure verdict (fail-closed).
     */
    public static function parseFailure(string $verdict = self::VERDICT_CHECKING): self
    {
        return new self(
            verdict: $verdict,
            category: self::CATEGORY_NONE,
            rationale: 'parse failed — fail closed',
            parseError: true,
        );
    }

    /**
     * Create a "ok" verdict (e.g. when classifier is disabled in testing).
     */
    public static function ok(): self
    {
        return new self(self::VERDICT_OK, self::CATEGORY_NONE, 'ok');
    }

    public function isFlagged(): bool
    {
        return $this->verdict === self::VERDICT_FLAGGED;
    }

    public function isChecking(): bool
    {
        return $this->verdict === self::VERDICT_CHECKING;
    }

    public function isOk(): bool
    {
        return $this->verdict === self::VERDICT_OK;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'verdict'     => $this->verdict,
            'category'    => $this->category,
            'rationale'   => $this->rationale,
            'parse_error' => $this->parseError,
        ];
    }
}
