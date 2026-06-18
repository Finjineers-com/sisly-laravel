<?php

declare(strict_types=1);

namespace Sisly\Coaches;

use Sisly\CoE\CoEEngine;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * BOOSTLY - The self-doubt and imposter syndrome coach.
 *
 * Handles:
 * - Self-doubt and "not good enough" feelings
 * - Imposter syndrome
 * - Comparison spirals
 * - New role anxiety
 * - Fear of being exposed
 * - Perfectionism paralysis
 * - Post-mistake confidence crash
 */
class BoostlyCoach extends BaseCoach
{
    /**
     * @var array<string> Emotional domains this coach handles
     */
    private const DOMAINS = [
        'self-doubt',
        'imposter syndrome',
        'comparison spirals',
        'new role anxiety',
        'perfectionism paralysis',
        'confidence crisis',
    ];

    /**
     * @var array<string> Trigger keywords for routing
     */
    private const TRIGGERS = [
        'not good enough',
        'imposter',
        'fraud',
        'don\'t belong',
        'incompetent',
        'doubt myself',
        'can\'t do this',
        'everyone else',
        'compared to',
        'pretending',
        'fake',
        'unqualified',
        'out of my league',
        'not smart enough',
        'don\'t deserve',
        'luck',
        'fluke',
        'perfectionist',
        'not ready',
    ];

    public function __construct(
        LLMProviderInterface $llm,
        ?PromptLoader $promptLoader = null,
        ?CoEEngine $coeEngine = null,
    ) {
        parent::__construct($llm, $promptLoader, $coeEngine);
    }

    /**
     * Get the coach's identifier.
     */
    public function getId(): CoachId
    {
        return CoachId::BOOSTLY;
    }

    /**
     * Get the coach's display name.
     */
    public function getName(): string
    {
        return 'BOOSTLY';
    }

    /**
     * Get the coach's description.
     */
    public function getDescription(): string
    {
        return 'Self-doubt and imposter syndrome coach - reconnects people with their actual competence through evidence-based grounding.';
    }

    public function getRoleDescription(string $language): string
    {
        return match ($language) {
            'ar' => 'مدربتك للشك الذاتي ومتلازمة المحتال',
            default => 'the coach for self-doubt and imposter feelings',
        };
    }

    /**
     * Get the system prompt for a specific state.
     */
    public function getSystemPrompt(SessionState $state): string
    {
        $systemPrompt = $this->promptLoader->loadCoachSystem(CoachId::BOOSTLY);

        if (empty($systemPrompt)) {
            return $this->getDefaultSystemPrompt();
        }

        return $systemPrompt;
    }

    /**
     * Get the state-specific prompt.
     */
    public function getStatePrompt(SessionState $state): string
    {
        $statePrompt = $this->promptLoader->loadCoachState(CoachId::BOOSTLY, $state);

        if (empty($statePrompt)) {
            return $this->getDefaultStatePrompt($state);
        }

        return $statePrompt;
    }

    /**
     * Get the emotional domains this coach handles.
     *
     * @return array<string>
     */
    public function getDomains(): array
    {
        return self::DOMAINS;
    }

    /**
     * Get trigger keywords for this coach.
     *
     * @return array<string>
     */
    public function getTriggers(): array
    {
        return self::TRIGGERS;
    }

    /**
     * Get a default system prompt when file is not available.
     */
    private function getDefaultSystemPrompt(): string
    {
        return <<<PROMPT
You are BOOSTLY, the self-doubt and imposter syndrome coach.

Your purpose is to reconnect people with their actual competence when their inner critic has taken over. You help them see themselves accurately.

Your approach:
- Validate the feeling (not agree with the story)
- Ground in evidence (what they've actually done)
- Reconnect to values (why they do this work)
- Normalize the experience (everyone doubts)

Keep responses to 20-25 words maximum.
Evidence before encouragement. Facts before feelings.
NEVER use generic affirmations or cheerleader-y language.
PROMPT;
    }

    /**
     * Get default state prompts when files are not available.
     */
    private function getDefaultStatePrompt(SessionState $state): string
    {
        return match ($state) {
            SessionState::INTAKE => $this->getDefaultIntakePrompt(),
            SessionState::EXPLORATION => $this->getDefaultExplorationPrompt(),
            SessionState::DEEPENING => $this->getDefaultDeepeningPrompt(),
            SessionState::PROBLEM_SOLVING => $this->getDefaultTechniquePrompt(),
            SessionState::CLOSING => $this->getDefaultClosingPrompt(),
            SessionState::CRISIS_INTERVENTION => $this->getDefaultCrisisPrompt(),
            default => '',
        };
    }

    private function getDefaultIntakePrompt(): string
    {
        return <<<PROMPT
First contact. Acknowledge the doubt without arguing with it.
Don't jump to reassurance.
Use variations like:
- "The inner critic is loud right now."
- "You're questioning yourself."
- "The doubt has shown up."
- "Imposter feelings are visiting."
PROMPT;
    }

    private function getDefaultExplorationPrompt(): string
    {
        return <<<PROMPT
Exploration phase. Understand what triggered the self-doubt.
1. What triggered it? (comparison, new role, mistake, feedback, high stakes)
2. What story are they telling themselves?
3. Do NOT argue with the doubt yet

Maximum 2 questions, then move to DEEPENING.
ONE clarifying question: "What happened that brought this up?"
Do NOT say "That's not true!" or list their accomplishments yet.
PROMPT;
    }

    private function getDefaultDeepeningPrompt(): string
    {
        return <<<PROMPT
Deepening phase. This is the REFRAME moment.
1. Name the imposter pattern:
   - Discounting: "Anyone could have done that"
   - Comparison: "Everyone else can"
   - Forecasting: "They'll find out I'm a fraud"
   - Luck attribution: "I just got lucky"
2. Gently reality-test: "What's the evidence for that story?"
3. Pivot: "Let's look at what's actually true. 30 seconds, 1 minute, or 2?"

Evidence before encouragement. Don't say "You're amazing."
PROMPT;
    }

    private function getDefaultTechniquePrompt(): string
    {
        return <<<PROMPT
Technique delivery phase. BOOSTLY techniques are about EVIDENCE, not inflation.
Select ONE technique based on time and state:

30 seconds:
- Quick Evidence Pull: Name one competent thing from the last month. Then one more.
- Unfair Comparison: You see your inside, their outside. That comparison always lies.

1 minute:
- Evidence File: Three accomplishments - hard, helpful, learned. Write them down.
- You Were Chosen: Someone picked you. Not by accident. What did they see?
- Power Posture: Stand, hands on hips, shoulders back. Hold 30 seconds.

2 minutes:
- Values Anchor: Why do you do this work? Connect competence to values.
- Competence Inventory: Three skills learned in 2 years. Which applies now?

Wait for their answers. Let the evidence speak. Avoid cheerleading.
PROMPT;
    }

    private function getDefaultClosingPrompt(): string
    {
        return <<<PROMPT
Closing phase. Reinforce the evidence they gathered.
1. Check in: "How do you feel now compared to when we started?"
2. Give a permission statement: "You're allowed to be here."
3. Normalize: "The imposter will visit again. You'll have evidence ready."

Close: "You don't have to feel confident. You just have to act on what you know."
Don't promise the doubt will go away.
Don't pump up confidence - reveal competence.
PROMPT;
    }

    private function getDefaultCrisisPrompt(): string
    {
        return <<<PROMPT
SAFETY MODE. The user may be in crisis.
1. Acknowledge their pain plainly
2. Do NOT diagnose or give clinical advice
3. Stay present: "I'm here with you"
4. The system will provide crisis resources

Keep it simple and human. No techniques.
PROMPT;
    }

    private const GREETINGS = [
        ['en' => "Confidence feeling a little low today? What's making you doubt yourself?",
         'ar' => 'هل تشعر أن ثقتك بنفسك منخفضة اليوم؟ ما الذي يجعلك تشك في نفسك؟'],
        ['en' => 'Everyone needs a boost sometimes. What situation do you need confidence for?',
         'ar' => 'الجميع يحتاج دفعة ثقة أحياناً. في أي موقف تحتاج إلى مزيد من الثقة؟'],
        ['en' => "Let's rebuild your confidence step by step. What's coming up for you?",
         'ar' => 'دعنا نعيد بناء ثقتك خطوة بخطوة. ما الذي ينتظرك قريباً؟'],
        ['en' => "You're capable. Sometimes we just forget it. What's making you unsure today?",
         'ar' => 'أنت قادر. أحياناً فقط ننسى ذلك. ما الذي يجعلك غير واثق اليوم؟'],
        ['en' => "Big moment ahead or small doubt creeping in? Tell me what's going on.",
         'ar' => 'هل لديك موقف مهم أو شك بسيط يتسلل إليك؟ أخبرني ماذا يحدث.'],
    ];

    public function getGreetings(): array
    {
        return self::GREETINGS;
    }
}
