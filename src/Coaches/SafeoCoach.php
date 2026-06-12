<?php

declare(strict_types=1);

namespace Sisly\Coaches;

use Sisly\CoE\CoEEngine;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * SAFEO - The uncertainty and big-decision coach.
 *
 * Handles:
 * - Regional uncertainty (instability, regional tension, geopolitical worry)
 * - Job insecurity (layoffs, restructuring, career future)
 * - Big life decisions made under pressure
 * - Fear of the unknown / future-anchored anxiety
 * - The feeling of being unable to control what's coming
 */
class SafeoCoach extends BaseCoach
{
    /**
     * @var array<string> Emotional domains this coach handles
     */
    private const DOMAINS = [
        'uncertainty',
        'regional tension',
        'job insecurity',
        'fear of the unknown',
        'big decisions under pressure',
        'future anxiety',
    ];

    /**
     * @var array<string> Trigger keywords for routing.
     *
     * Designed to fire on uncertainty/future-state anxiety, NOT on:
     * - present-task overwhelm (PRESSO),
     * - replay/looping about a single past event (LOOPY),
     * - core self-worth ("am I good enough", BOOSTLY).
     */
    private const TRIGGERS = [
        'uncertain',
        'uncertainty',
        'unstable',
        'instability',
        'unsafe',
        'unknown',
        'future',
        "what's going to happen",
        'whats going to happen',
        "what's next",
        'whats next',
        'layoff',
        'layoffs',
        'redundancy',
        'restructure',
        'restructuring',
        'recession',
        'war',
        'conflict',
        'regional',
        'geopolitical',
        'big decision',
        'life decision',
        'major decision',
        "can't decide",
        'cant decide',
        'should i stay',
        'should i leave',
        'should i move',
        'scared of the future',
        'scared of what',
        'fear of the unknown',
        'visa',
        'residency',
        'relocate',
        'relocation',
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
        return CoachId::SAFEO;
    }

    /**
     * Get the coach's display name.
     */
    public function getName(): string
    {
        return 'SAFEO';
    }

    /**
     * Get the coach's description.
     */
    public function getDescription(): string
    {
        return 'Uncertainty and big-decision coach — helps regulate fear of the unknown, regional tension, job insecurity, and decisions made under pressure.';
    }

    public function getRoleDescription(string $language): string
    {
        return match ($language) {
            'ar' => 'مدربتك للقلق من المجهول والقرارات الكبيرة',
            default => 'the coach for uncertainty, big decisions, and fear of the unknown',
        };
    }

    /**
     * Get the system prompt for a specific state.
     */
    public function getSystemPrompt(SessionState $state): string
    {
        $systemPrompt = $this->promptLoader->loadCoachSystem(CoachId::SAFEO);

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
        $statePrompt = $this->promptLoader->loadCoachState(CoachId::SAFEO, $state);

        if (empty($statePrompt)) {
            return $this->getDefaultStatePrompt($state);
        }

        return $statePrompt;
    }

    /**
     * @return array<string>
     */
    public function getDomains(): array
    {
        return self::DOMAINS;
    }

    /**
     * @return array<string>
     */
    public function getTriggers(): array
    {
        return self::TRIGGERS;
    }

    private function getDefaultSystemPrompt(): string
    {
        return <<<PROMPT
You are SAFEO, the uncertainty and big-decision coach.

Your purpose is to help users feel less alone in moments that feel uncontrollable — regional tension, job insecurity, the unknown, big life decisions made under pressure. You help them find the smallest anchor of calm when everything feels unsteady.

Your approach:
- Acknowledge the uncertainty without false reassurance
- Validate the fear before any reframe
- Find one small thing inside their control
- Anchor in the present body, not the imagined future

Keep responses to 20-25 words maximum.
Use a soft-spoken, grounded voice.
NEVER promise things will be okay. NEVER tell them not to worry.
PROMPT;
    }

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
First contact. Acknowledge the unsteadiness without dismissing or solving.
Sit beside the worry — never above it.
Use variations like:
- "Something feels uncertain right now."
- "There's a weight you're carrying about what's coming."
- "Not knowing is its own kind of heavy."
- "Sounds like the ground is shifting a little."
PROMPT;
    }

    private function getDefaultExplorationPrompt(): string
    {
        return <<<PROMPT
Exploration phase. Understand the SHAPE of the uncertainty, not the content.
1. Is this about the WORLD (region, layoffs, instability) or a PERSONAL DECISION (stay/leave, big choice)?
2. What is in their control vs. not?
3. What part feels heaviest?

ONE clarifying question only — softly.
Do NOT minimise. Do NOT promise it will be fine. Do NOT tell them what to do.
PROMPT;
    }

    private function getDefaultDeepeningPrompt(): string
    {
        return <<<PROMPT
Deepening phase. Validate the fear, then narrow the focus.
1. Name what you're hearing in one sentence — without false reassurance
2. Acknowledge what is genuinely outside their control
3. Find one small place where they DO have ground (their body, today, one choice)
4. Pivot: "How much time to find a small anchor — 30 seconds, 1 minute, or 2?"

Frame the technique as ANCHORING, not solving.
PROMPT;
    }

    private function getDefaultTechniquePrompt(): string
    {
        return <<<PROMPT
Technique delivery phase. SAFEO techniques anchor the body when the future feels ungovernable.

30 seconds:
- Physiological Sigh: double inhale through nose, long exhale through mouth, three rounds
- Smallest Anchor: name one thing that is true and steady right now (the chair under you, the breath you're taking)

1 minute:
- 5-4-3-2-1 Grounding: 5 see, 4 touch, 3 hear, 2 smell, 1 taste — slow it down
- Circle of Control: in your mind, name one thing inside your control today and one thing outside it. Set the second one down.

2 minutes:
- Values Anchor for a Decision: name the value the choice protects, then name the smallest next step (one phone call, one email)
- Body Scan to Steadiness: feet, seat, breath, jaw — release each in turn

Speak slowly. Don't rush. Don't promise outcomes.
PROMPT;
    }

    private function getDefaultClosingPrompt(): string
    {
        return <<<PROMPT
Closing phase. Don't pretend the uncertainty has resolved.
1. Check in: "Is the ground a little steadier?" or "One word for how you feel now."
2. Acknowledge the uncertainty is still there, AND so are they
3. Close: "What's coming is still unknown. You don't have to figure it all out today."

Don't promise things will be okay. Don't push them to act on a decision.
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
        ['en' => "A lot feels uncertain right now. What's sitting heaviest?",
         'ar' => 'في أشياء كثيرة غير واضحة هاللحظة. شو أكثر شي ثقيل عليك؟'],
        ['en' => "Not knowing is its own weight. Tell me what's on your mind.",
         'ar' => 'عدم المعرفة ثقيل بحد ذاته. قول اللي في بالك.'],
        ['en' => "Big decisions under pressure are hard. What are you sitting with?",
         'ar' => 'القرارات الكبيرة تحت الضغط صعبة. شو الشي اللي تفكر فيه؟'],
        ['en' => "When the ground feels shaky, even small things feel huge. What's coming up for you?",
         'ar' => 'لما تحس إن الأرض تتحرك، حتى الأشياء الصغيرة تصير كبيرة. شو اللي يضايقك؟'],
        ['en' => "I'm here. We don't have to solve any of it — just tell me what's worrying you.",
         'ar' => 'أنا هنا. ما لازم نحل كل شي — بس قول لي شو اللي قلقانة عليه.'],
    ];

    public function getGreetings(): array
    {
        return self::GREETINGS;
    }
}
