<?php

declare(strict_types=1);

namespace Sisly\DTOs;

use Sisly\Enums\CoachId;

/**
 * Coach information for listing.
 */
final class CoachInfo
{
    /**
     * @param array<string> $triggers
     * @param array<string> $inScope
     * @param array<string> $outOfScope
     */
    public function __construct(
        public readonly CoachId $id,
        public readonly string $name,
        public readonly string $description,
        public readonly array $triggers,
        public readonly array $inScope,
        public readonly array $outOfScope,
    ) {}

    /**
     * Get all coach info definitions.
     *
     * @return array<CoachInfo>
     */
    public static function all(): array
    {
        return [
            self::meetly(),
            self::vento(),
            self::loopy(),
            self::presso(),
            self::boostly(),
        ];
    }

    /**
     * Get MEETLY coach info.
     */
    public static function meetly(): self
    {
        return new self(
            id: CoachId::MEETLY,
            name: 'Meetly',
            description: 'Your calm companion for meeting and presentation anxiety',
            triggers: ['meeting', 'presentation', 'interview', 'speaking', 'nervous'],
            inScope: [
                'Pre-meeting jitters',
                'Presentation anxiety',
                'Interview nerves',
                'Fear of speaking up',
                'Performance anxiety',
            ],
            outOfScope: [
                'Social anxiety disorder',
                'Chronic anxiety',
                'Panic attacks',
            ],
        );
    }

    /**
     * Get VENTO coach info.
     */
    public static function vento(): self
    {
        return new self(
            id: CoachId::VENTO,
            name: 'Vento',
            description: 'A safe space to release frustration and process anger',
            triggers: ['angry', 'frustrated', 'furious', 'annoyed', 'vent'],
            inScope: [
                'Work frustrations',
                'Interpersonal conflicts',
                'Daily irritations',
                'Need to vent',
                'Processing anger',
            ],
            outOfScope: [
                'Rage disorders',
                'Violent thoughts',
                'Domestic violence situations',
            ],
        );
    }

    /**
     * Get LOOPY coach info.
     */
    public static function loopy(): self
    {
        return new self(
            id: CoachId::LOOPY,
            name: 'Loopy',
            description: 'Breaking free from thought loops and overthinking',
            triggers: ['overthinking', 'cant stop thinking', 'what if', 'ruminating', 'stuck'],
            inScope: [
                'Repetitive thoughts',
                'What-if spirals',
                'Overanalyzing decisions',
                'Replaying conversations',
                'Worry loops',
            ],
            outOfScope: [
                'OCD',
                'Intrusive thoughts disorder',
                'Severe anxiety',
            ],
        );
    }

    /**
     * Get PRESSO coach info.
     */
    public static function presso(): self
    {
        return new self(
            id: CoachId::PRESSO,
            name: 'Presso',
            description: 'Finding clarity when everything feels like too much',
            triggers: ['overwhelmed', 'too much', 'deadline', 'stressed', 'pressure'],
            inScope: [
                'Work overload',
                'Deadline pressure',
                'Task overwhelm',
                'Feeling stretched thin',
                'Prioritization paralysis',
            ],
            outOfScope: [
                'Burnout syndrome',
                'Chronic stress disorder',
                'Work addiction',
            ],
        );
    }

    /**
     * Get BOOSTLY coach info.
     */
    public static function boostly(): self
    {
        return new self(
            id: CoachId::BOOSTLY,
            name: 'Boostly',
            description: 'Building confidence when self-doubt creeps in',
            triggers: ['not good enough', 'imposter', 'doubt myself', 'fraud', 'inadequate'],
            inScope: [
                'Imposter feelings',
                'Self-doubt moments',
                'Comparison spirals',
                'Achievement minimization',
                'Fear of being exposed',
            ],
            outOfScope: [
                'Clinical depression',
                'Low self-esteem disorder',
                'Body dysmorphia',
            ],
        );
    }

    /**
     * Get coach info by ID.
     */
    public static function byId(CoachId $id): self
    {
        return match ($id) {
            CoachId::MEETLY => self::meetly(),
            CoachId::VENTO => self::vento(),
            CoachId::LOOPY => self::loopy(),
            CoachId::PRESSO => self::presso(),
            CoachId::BOOSTLY => self::boostly(),
        };
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'name' => $this->name,
            'description' => $this->description,
            'triggers' => $this->triggers,
            'in_scope' => $this->inScope,
            'out_of_scope' => $this->outOfScope,
        ];
    }
}
