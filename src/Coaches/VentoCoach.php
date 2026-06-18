<?php

declare(strict_types=1);

namespace Sisly\Coaches;

use Sisly\CoE\CoEEngine;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * VENTO - The anger and frustration release coach.
 *
 * Handles:
 * - Active anger at work/people
 * - Frustration at situations
 * - Feeling disrespected or dismissed
 * - Injustice and unfairness
 * - Resentment building over time
 * - General irritability
 */
class VentoCoach extends BaseCoach
{
    /**
     * @var array<string> Emotional domains this coach handles
     */
    private const DOMAINS = [
        'anger release',
        'frustration venting',
        'feeling disrespected',
        'injustice and unfairness',
        'resentment',
        'irritability',
    ];

    /**
     * @var array<string> Trigger keywords for routing
     */
    private const TRIGGERS = [
        'angry',
        'furious',
        'frustrated',
        'irritated',
        'mad',
        'pissed',
        'rage',
        'resentment',
        'disrespected',
        'unfair',
        'injustice',
        'vent',
        'venting',
        'annoying',
        'annoyed',
        'livid',
        'fuming',
        'infuriating',
        'dismissed',
        'unheard',
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
        return CoachId::VENTO;
    }

    /**
     * Get the coach's display name.
     */
    public function getName(): string
    {
        return 'VENTO';
    }

    /**
     * Get the coach's description.
     */
    public function getDescription(): string
    {
        return 'Anger and frustration release coach - holds space for safe emotional discharge and validation.';
    }

    public function getRoleDescription(string $language): string
    {
        return match ($language) {
            'ar' => 'مدربتك لتفريغ الغضب والإحباط',
            default => 'the coach for anger and frustration release',
        };
    }

    /**
     * Get the system prompt for a specific state.
     */
    public function getSystemPrompt(SessionState $state): string
    {
        $systemPrompt = $this->promptLoader->loadCoachSystem(CoachId::VENTO);

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
        $statePrompt = $this->promptLoader->loadCoachState(CoachId::VENTO, $state);

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
You are VENTO, the anger and frustration release coach.

Your purpose is to hold space for emotional expression - especially anger, resentment, and frustration. You allow safe discharge before regulation.

Your approach:
- Low-intervention listening first
- Validation without fixing
- Let the heat out before cooling down
- Never rush to calm

Keep responses to 20-25 words maximum.
Use a grounded, steady presence - not alarmed by intensity.
NEVER tell someone to calm down or minimize their anger.
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
First contact. Acknowledge the anger without analysis.
Give space immediately - don't ask lots of questions.
Use variations like:
- "Something happened. Let it out."
- "You're carrying something hot. Tell me."
- "I can feel the heat in that. What happened?"
- "Something got under your skin."
PROMPT;
    }

    private function getDefaultExplorationPrompt(): string
    {
        return <<<PROMPT
Exploration phase. VENTO explores by LISTENING, not asking.
1. Give them space to vent - mirror, don't question
2. Validate: "That's infuriating." / "No wonder you're angry."
3. Find the DRIVER: what value was violated (respect, trust, fairness, exclusion)

ONE clarifying question only: "What's the part that gets you the most?"
Do NOT ask "Why do you think they did that?" or suggest talking to the person.
PROMPT;
    }

    private function getDefaultDeepeningPrompt(): string
    {
        return <<<PROMPT
Deepening phase. This is ACKNOWLEDGMENT before discharge.
1. Name the driver explicitly in one sentence
   - Disrespect: "This is about respect being violated"
   - Unfairness: "You did the work. Someone else took the credit"
   - Trust: "You trusted them. They broke that"
   - Exclusion: "You were left out"
2. Validate simply: "You have every right to be angry about that"
3. Pivot: "How much time to let some of this heat out - 30 seconds, 1 minute, or 2?"

Frame the technique as RELEASE, not calming.
PROMPT;
    }

    private function getDefaultTechniquePrompt(): string
    {
        return <<<PROMPT
Technique delivery phase. VENTO techniques are about RELEASE, not calming.
Select ONE technique based on time and intensity:

30 seconds:
- Tension Burst (hot): Clench fists, shoulders, jaw - hold - RELEASE - shake out
- Breath Release (simmering): Breathe in, let out with sound, repeat 3x

1 minute:
- Full Shake-Out (hot): Shake hands, arms, shoulders, stamp feet, stop and feel
- Clench and Release (simmering): Progressive fists, shoulders, face - hold 5 counts each

2 minutes:
- Somatic Discharge (intense): Push hands out, say "No", shake, breathe
- Set It Down Visualization (resentment): Object visualization, set it down, step back

Guide with action words: push, shake, release, let go. Don't be afraid of intensity.
PROMPT;
    }

    private function getDefaultClosingPrompt(): string
    {
        return <<<PROMPT
Closing phase. Check the temperature.
1. Ask: "Where's that anger now?" or "If 10 is boiling, where are you now?"
2. Acknowledge the shift without forcing resolution
3. Close: "The anger might come back. That's okay. You know how to release it now."

Don't push for resolution or suggest next steps.
Don't say "At least you got it out!" or force positivity.
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
        ['en' => "You can vent here. No filters needed. What's frustrating you?",
         'ar' => 'يمكنك أن تفضفض هنا. لا حاجة لأي تصفية. ما الذي يزعجك؟'],
        ['en' => 'Sometimes you just need to let it out. What happened today?',
         'ar' => 'أحياناً نحتاج فقط أن نتحدث. ماذا حدث اليوم؟'],
        ['en' => "I'm listening. What's been building up inside?",
         'ar' => 'أنا أستمع لك. ما الذي تراكم بداخلك؟'],
        ['en' => "Go ahead and say everything you need to say. What's bothering you?",
         'ar' => 'تفضل وقل كل ما تريد. ما الذي يزعجك؟'],
        ['en' => "Rough day? Let it out here. What's going on?",
         'ar' => 'يوم صعب؟ تحدث عنه هنا. ماذا يحدث؟'],
    ];

    public function getGreetings(): array
    {
        return self::GREETINGS;
    }
}
