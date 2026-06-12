<?php

declare(strict_types=1);

namespace Sisly\Coaches;

use Sisly\Contracts\CoachInterface;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\Enums\CoachId;
use Sisly\Exceptions\SislyException;

/**
 * Registry for managing coach instances.
 */
class CoachRegistry
{
    /**
     * @var array<string, CoachInterface>
     */
    private array $coaches = [];

    /**
     * @var array<string> Enabled coach IDs
     */
    private array $enabledCoaches;

    /**
     * @param array<string> $enabledCoaches
     */
    public function __construct(
        private readonly LLMProviderInterface $llm,
        private readonly ?PromptLoader $promptLoader = null,
        array $enabledCoaches = [],
    ) {
        $this->enabledCoaches = $enabledCoaches ?: CoachId::values();
    }

    /**
     * Get a coach by ID.
     *
     * @throws SislyException
     */
    public function get(CoachId $coachId): CoachInterface
    {
        $key = $coachId->value;

        if (!$this->isEnabled($coachId)) {
            throw new SislyException("Coach '{$key}' is not enabled.");
        }

        if (!isset($this->coaches[$key])) {
            $this->coaches[$key] = $this->createCoach($coachId);
        }

        return $this->coaches[$key];
    }

    /**
     * Check if a coach is enabled.
     */
    public function isEnabled(CoachId $coachId): bool
    {
        return in_array($coachId->value, $this->enabledCoaches, true);
    }

    /**
     * Get all enabled coaches.
     *
     * @return array<CoachInterface>
     */
    public function getAllEnabled(): array
    {
        $coaches = [];

        foreach ($this->enabledCoaches as $coachIdValue) {
            $coachId = CoachId::from($coachIdValue);
            $coaches[] = $this->get($coachId);
        }

        return $coaches;
    }

    /**
     * Get enabled coach IDs.
     *
     * @return array<string>
     */
    public function getEnabledIds(): array
    {
        return $this->enabledCoaches;
    }

    /**
     * Register a custom coach.
     */
    public function register(CoachInterface $coach): void
    {
        $this->coaches[$coach->getId()->value] = $coach;
    }

    /**
     * Create a coach instance.
     */
    private function createCoach(CoachId $coachId): CoachInterface
    {
        $promptLoader = $this->promptLoader ?? new PromptLoader();

        return match ($coachId) {
            CoachId::MEETLY => new MeetlyCoach($this->llm, $promptLoader),
            CoachId::VENTO => new VentoCoach($this->llm, $promptLoader),
            CoachId::LOOPY => new LoopyCoach($this->llm, $promptLoader),
            CoachId::PRESSO => new PressoCoach($this->llm, $promptLoader),
            CoachId::BOOSTLY => new BoostlyCoach($this->llm, $promptLoader),
            CoachId::SAFEO => new SafeoCoach($this->llm, $promptLoader),
        };
    }
}
