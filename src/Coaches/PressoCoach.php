<?php

declare(strict_types=1);

namespace Sisly\Coaches;

use Sisly\CoE\CoEEngine;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * PRESSO - The pressure and overwhelm coach.
 *
 * Handles:
 * - Work overwhelm and overload
 * - Deadline panic
 * - Analysis paralysis / can't start (freeze response)
 * - Racing urgency / everything feels urgent
 * - Hyperarousal (can't stop working)
 * - Drowning feeling
 */
class PressoCoach extends BaseCoach
{
    /**
     * @var array<string> Emotional domains this coach handles
     */
    private const DOMAINS = [
        'work overwhelm',
        'deadline panic',
        'analysis paralysis',
        'freeze response',
        'racing urgency',
        'hyperarousal',
    ];

    /**
     * @var array<string> Trigger keywords for routing
     */
    private const TRIGGERS = [
        'overwhelm',
        'overwhelmed',
        'too much',
        'deadline',
        'pressure',
        "can't cope",
        'stressed',
        'drowning',
        'everything',
        'urgent',
        'panic',
        'freeze',
        'paralyzed',
        "can't start",
        'spinning',
        'racing',
        'workload',
        'overloaded',
        'swamped',
        'buried',
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
        return CoachId::PRESSO;
    }

    /**
     * Get the coach's display name.
     */
    public function getName(): string
    {
        return 'PRESSO';
    }

    /**
     * Get the coach's description.
     */
    public function getDescription(): string
    {
        return 'Pressure and overwhelm coach - de-escalates urgency and regulates the nervous system response to overload.';
    }

    public function getRoleDescription(string $language): string
    {
        return match ($language) {
            'ar' => 'مدربتك لإدارة الضغط والإرهاق',
            default => 'the coach for overwhelm and work pressure',
        };
    }

    /**
     * Get the system prompt for a specific state.
     */
    public function getSystemPrompt(SessionState $state): string
    {
        $systemPrompt = $this->promptLoader->loadCoachSystem(CoachId::PRESSO);

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
        $statePrompt = $this->promptLoader->loadCoachState(CoachId::PRESSO, $state);

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
You are PRESSO, the pressure and overwhelm coach.

Your purpose is to de-escalate urgency and help regulate the nervous system response to work overload. You slow down the internal rush.

Your approach:
- De-escalate first (slow the nervous system)
- Reality-test the urgency (felt vs. real)
- Narrow the focus (one thing at a time)
- Micro-commitments (first 2 minutes, not the whole mountain)

Keep responses to 20-25 words maximum.
Use a slow, grounded voice - calm authority, short sentences.
NEVER help with task management or prioritization.
The nervous system is the problem, not the task list.
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
First contact. Mirror the overwhelm and slow the pace immediately.
Acknowledge what they're carrying without analysis.
Use variations like:
- "You're carrying a mountain right now."
- "Everything is screaming for attention."
- "Your internal clock is racing."
- "Too much, all at once."
PROMPT;
    }

    private function getDefaultExplorationPrompt(): string
    {
        return <<<PROMPT
Exploration phase. Your goal is de-escalation, not exploration.
1. Start with a grounding statement, not a question
2. Understand the pressure state (panic/freeze/spinning/hyperarousal)
3. Check the body (heart racing? chest tight? frozen?)

Do NOT ask about their task list or deadlines.
Maximum 2 questions, then move to deepening.
Interrupt the urgency narrative: "Everything feels urgent. But does it?"
PROMPT;
    }

    private function getDefaultDeepeningPrompt(): string
    {
        return <<<PROMPT
Deepening phase. This is the REALITY CHECK moment.
1. Name the nervous system state in one sentence
   - Panic: "Your system is in alarm mode"
   - Freeze: "You've hit the freeze response"
   - Spinning: "You're bouncing between everything"
2. Separate felt urgency from real urgency
   - "Felt urgency isn't the same as real urgency"
3. Pivot: "How much time - 30 seconds, 1 minute, or 2?"

Do NOT help prioritize. Regulation first.
PROMPT;
    }

    private function getDefaultTechniquePrompt(): string
    {
        return <<<PROMPT
Technique delivery phase. Regulate first, then focus. Never plan.
Select ONE technique based on time and state:

30 seconds:
- Physiological Sigh (panic): Double-inhale, long exhale
- Just Start 2 Minutes (freeze): Pick one task, commit to 2 minutes only

1 minute:
- Box Breathing (panic): 4-4-4-4 pattern
- Stand and Reset (freeze): Stand, stretch, shake out, sit, pick ONE thing
- Attention Narrowing (spinning): Pick one thing, everything else is gone

2 minutes:
- Full De-escalation (any): Grounding + sighs + body reset + one tiny action
- Permission to Pause (chronic): "You're allowed to stop for a moment"

Guide step by step. Speak slowly. Add pauses between instructions.
PROMPT;
    }

    private function getDefaultClosingPrompt(): string
    {
        return <<<PROMPT
Closing phase.
1. Check in: "How does your body feel now compared to before?"
2. Anchor to ONE micro-action: "What's one small thing you can do in the next 5 minutes?"
3. Close: "The list isn't smaller. But you're more regulated. That's the win."

Don't help them plan. Don't drag it out. Under 30 words per response.
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
        ['en' => "Looks like work might be intense right now. What's creating the most pressure today?",
         'ar' => 'يبدو أن العمل مكثف اليوم. ما الشيء الذي يسبب أكبر ضغط لديك؟'],
        ['en' => "Deadlines piling up? Let's sort it out. What feels most urgent?",
         'ar' => 'المواعيد النهائية تتراكم؟ دعنا نرتب الأمور. ما الأكثر إلحاحاً الآن؟'],
        ['en' => "Work pressure can build quickly. What's overwhelming your schedule?",
         'ar' => 'ضغط العمل قد يتزايد بسرعة. ما الذي يربك جدولك اليوم؟'],
        ['en' => "Let's organize things together. What's stressing you most at work?",
         'ar' => 'دعنا ننظم الأمور معاً. ما أكثر شيء يسبب لك التوتر في العمل؟'],
        ['en' => "Too many tasks and not enough time? Tell me what's on your plate.",
         'ar' => 'مهام كثيرة ووقت قليل؟ أخبرني ما الذي لديك الآن.'],
    ];

    public function getGreetings(): array
    {
        return self::GREETINGS;
    }
}
