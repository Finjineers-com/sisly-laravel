<?php

declare(strict_types=1);

namespace Sisly\Safety;

use Sisly\Contracts\LLMProviderInterface;
use Sisly\DTOs\SafetyVerdict;

/**
 * LLM-based parallel safety classifier.
 *
 * Spec: "Fire two calls in parallel: safety classifier with SAFETY_SYS
 * (cheap/fast model), coach reply with shared spine + persona. The safety
 * verdict overrides the coach."
 *
 * This class handles the SECOND safety layer (LLM-based).
 * The FIRST layer (deterministic keyword) is CrisisDetector — it runs before
 * any LLM calls and is the primary safeguard.
 *
 * This classifier maps "flagged" → handled by CrisisHandler (same path as
 * keyword detection). "checking" → keep coach reply, surface amber badge.
 */
class SafetyClassifier
{
    /**
     * The SAFETY_SYS prompt from the functional spec (verbatim).
     */
    private const SAFETY_SYS = <<<'PROMPT'
You are a safety classifier for a non-clinical workplace mood app used in the UAE. You do not chat. You read the latest user message (and brief context) and return ONLY a JSON verdict. You err toward caution.

Return exactly:
{ "verdict": "ok" | "checking" | "flagged",
  "category": "none|self_harm|harm_to_others|abuse|medical_emergency|acute_distress",
  "rationale": "one short line" }

flagged  = any sign of self-harm or suicide, intent or plan to harm another person,
           disclosure of abuse or being in danger, or a medical emergency. When flagged,
           the app stops coaching and shows crisis resources.
checking = ambiguous or worrying language that is not clearly a crisis (e.g. "I can't do
           this anymore" with no clear meaning, heavy hopelessness). The app shows a
           gentle check and a soft offer of resources, and keeps a close eye.
ok       = ordinary workday stress, sadness, frustration, tiredness, overwhelm with no
           risk signal.

RULES
- Judge the user, never the coach.
- Detect risk in English AND Arabic, including Gulf dialect and mixed Arabic/English.
- Idioms of exhaustion ("I'm dead", "killing me", "I'm done") are usually NOT crisis in a
  work context. Use surrounding meaning. Do not over-flag ordinary venting.
- When genuinely unsure between ok and checking, choose checking. Between checking and
  flagged, choose flagged.
- Output JSON only. No prose, no markdown.
PROMPT;

    public function __construct(
        private readonly LLMProviderInterface $llm,
        private readonly string $failClosedVerdict = SafetyVerdict::VERDICT_CHECKING,
    ) {}

    /**
     * Classify a user message.
     *
     * This is designed to be called in parallel with the coach call.
     * The implementation is synchronous; the parallel execution is managed
     * at the SislyManager level using PHP fibers or async adapters when
     * the hosting stack supports it. For synchronous stacks, both calls
     * complete sequentially — latency increases but safety is preserved.
     */
    public function classify(string $userMessage): SafetyVerdict
    {
        try {
            $response = $this->llm->chat(
                messages: [['role' => 'user', 'content' => $userMessage]],
                systemPrompt: self::SAFETY_SYS,
                options: [
                    'temperature' => 0.0, // Always deterministic
                    'max_tokens'  => 200,
                ],
            );

            if (!$response->success) {
                return SafetyVerdict::parseFailure($this->failClosedVerdict);
            }

            return SafetyVerdict::fromJson($response->content, $this->failClosedVerdict);

        } catch (\Throwable) {
            // Any exception → fail closed
            return SafetyVerdict::parseFailure($this->failClosedVerdict);
        }
    }

    /**
     * Get the raw SAFETY_SYS prompt (for testing / introspection).
     */
    public function getSystemPrompt(): string
    {
        return self::SAFETY_SYS;
    }
}
