<?php

declare(strict_types=1);

namespace Sisly\Safety;

/**
 * Validates LLM responses to ensure they don't contain harmful content.
 *
 * This is a post-processing safety check that runs AFTER LLM generation.
 */
class PostResponseValidator
{
    /**
     * Prohibited patterns that should never appear in responses.
     *
     * @var array<string>
     */
    private array $prohibitedPatterns = [
        // Harmful suggestions
        '/you should (kill|harm|hurt) yourself/i',
        '/you should (kill|harm|hurt) (him|her|them|someone|anybody)/i',
        '/here\'s how to (kill|harm|hurt|end)/i',
        '/methods? (of|for|to) (suicide|self[- ]?harm|killing)/i',

        // Medical/clinical overreach
        '/you (have|are|seem to have|might have|probably have)\s+\w*\s*(depression|anxiety disorder|bipolar|schizophrenia|ptsd|ocd|bpd)/i',
        '/you are\s+\w+\s+(bipolar|depressed|schizophrenic|mentally ill)/i',
        '/i (diagnose|can diagnose) you (with|as)/i',
        '/your diagnosis is/i',

        // Medication advice
        '/you should (take|try|use|get)\s+\w*\s*(medication|pills|drugs|antidepressants|benzos|xanax|prozac|zoloft)/i',
        '/(try|using|get)\s+\w*\s*(xanax|prozac|zoloft|valium|klonopin|ativan)/i',
        '/increase your (dose|dosage|medication)/i',
        '/stop taking your (medication|pills|medicine)/i',
        '/(recommended|suggest) dosage/i',

        // Dangerous minimization
        '/suicide (is|isn\'t) (the answer|a solution|selfish)/i',
        '/just (get over it|snap out of it|cheer up|be happy|think positive)/i',
        '/(think positive|stay positive|be positive).*(will (all )?be (fine|ok|okay|better)|work out)/i',
        '/it\'s (not that bad|not a big deal|all in your head)/i',

        // Inappropriate relationship suggestions
        '/you should (leave|divorce|break up with)/i',
        '/your (partner|spouse|husband|wife) (is|sounds) (abusive|toxic|narcissist)/i',
    ];

    /**
     * Warning patterns that should be flagged but not blocked.
     *
     * @var array<string>
     */
    private array $warningPatterns = [
        // Potential clinical language
        '/\b(therapy|therapist|counseling|psychiatrist|psychologist)\b/i',
        '/\b(disorder|syndrome|condition)\b/i',

        // Giving specific advice
        '/you (must|need to|have to|should definitely)/i',
    ];

    /**
     * Validate a response for prohibited content.
     */
    public function validate(string $response): ValidationResult
    {
        // Check for prohibited patterns
        foreach ($this->prohibitedPatterns as $pattern) {
            if (preg_match($pattern, $response)) {
                return new ValidationResult(
                    valid: false,
                    blocked: true,
                    reason: 'Response contains prohibited content',
                    matchedPattern: $pattern,
                );
            }
        }

        // Check for warning patterns
        $warnings = [];
        foreach ($this->warningPatterns as $pattern) {
            if (preg_match($pattern, $response)) {
                $warnings[] = $pattern;
            }
        }

        if (!empty($warnings)) {
            return new ValidationResult(
                valid: true,
                blocked: false,
                reason: 'Response contains flagged content',
                warnings: $warnings,
            );
        }

        return new ValidationResult(valid: true);
    }

    /**
     * Sanitize a response by removing or replacing problematic content.
     */
    public function sanitize(string $response): string
    {
        // For now, we don't auto-sanitize - we just block
        // This could be extended to do smart replacements
        return $response;
    }

    /**
     * Check if a response is safe to send.
     */
    public function isSafe(string $response): bool
    {
        return $this->validate($response)->valid;
    }

    /**
     * Get a safe fallback response when validation fails.
     */
    public function getFallbackResponse(string $language = 'en'): string
    {
        return $language === 'ar'
            ? 'أنا هنا للاستماع إليك. هل يمكنك إخباري المزيد عما تشعر به؟'
            : "I'm here to listen. Can you tell me more about what you're experiencing?";
    }

    /**
     * Add a custom prohibited pattern.
     */
    public function addProhibitedPattern(string $pattern): void
    {
        $this->prohibitedPatterns[] = $pattern;
    }

    /**
     * Add a custom warning pattern.
     */
    public function addWarningPattern(string $pattern): void
    {
        $this->warningPatterns[] = $pattern;
    }
}

/**
 * Result of response validation.
 */
class ValidationResult
{
    /**
     * @param array<string> $warnings
     */
    public function __construct(
        public readonly bool $valid,
        public readonly bool $blocked = false,
        public readonly ?string $reason = null,
        public readonly ?string $matchedPattern = null,
        public readonly array $warnings = [],
    ) {}

    /**
     * Check if there are any warnings.
     */
    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }
}
