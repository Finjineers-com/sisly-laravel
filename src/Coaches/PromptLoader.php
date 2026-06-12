<?php

declare(strict_types=1);

namespace Sisly\Coaches;

use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\Exceptions\SislyException;

/**
 * Recognised reasons that override the default from→to bridge.
 *
 * Used by SislyManager when force-transitioning into CLOSING because
 * the wall-clock budget is approaching exhaustion — the bridge for
 * that case must NOT mention the time limit to the user.
 */

/**
 * Loads prompt files from the resources directory.
 *
 * Supports both package defaults and consumer overrides.
 */
class PromptLoader
{
    /**
     * @var string Base path for package prompts
     */
    private string $packagePath;

    /**
     * @var string|null Override path for consumer prompts
     */
    private ?string $overridePath;

    /**
     * @var array<string, string> Cached prompts
     */
    private array $cache = [];

    public function __construct(?string $overridePath = null)
    {
        $this->packagePath = __DIR__ . '/../../resources/prompts';
        $this->overridePath = $overridePath;
    }

    /**
     * Load a global prompt file.
     */
    public function loadGlobal(string $name): string
    {
        $cacheKey = "global:{$name}";

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $content = $this->loadFile("global/{$name}.md");
        $this->cache[$cacheKey] = $content;

        return $content;
    }

    /**
     * Load a coach-specific prompt file.
     */
    public function loadCoach(CoachId $coach, string $name): string
    {
        $coachDir = strtolower($coach->value);
        $cacheKey = "coach:{$coachDir}:{$name}";

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $content = $this->loadFile("coaches/{$coachDir}/{$name}.md");
        $this->cache[$cacheKey] = $content;

        return $content;
    }

    /**
     * Load the system prompt for a coach.
     */
    public function loadCoachSystem(CoachId $coach): string
    {
        return $this->loadCoach($coach, 'system');
    }

    /**
     * Load a state-specific prompt for a coach.
     */
    public function loadCoachState(CoachId $coach, SessionState $state): string
    {
        $stateName = $this->mapStateToPromptName($state);
        return $this->loadCoach($coach, $stateName);
    }

    /**
     * Load the transition bridge for a from→to state pair.
     *
     * Returns the bridge content from `global/transitions.md` matching the
     * given pair, or an empty string when no bridge applies. Bridges are
     * appended to the system prompt by BaseCoach for one turn only,
     * immediately following an FSM transition, to avoid abrupt shifts in
     * the bot's tone between coaching phases.
     *
     * The optional $reason qualifier overrides the default lookup. The
     * recognised value is `time_threshold` for force-transitions into
     * CLOSING driven by `fsm.max_session_seconds` — that bridge tells the
     * bot to wrap gracefully without naming the time limit.
     */
    public function loadTransitionBridge(
        SessionState $from,
        SessionState $to,
        ?string $reason = null,
    ): string {
        $sectionKey = $this->resolveBridgeSection($from, $to, $reason);

        if ($sectionKey === null) {
            return '';
        }

        $cacheKey = "transition_bridge:{$sectionKey}";

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $document = $this->loadGlobal('transitions');
        $section = $this->extractBridgeSection($document, $sectionKey);
        $this->cache[$cacheKey] = $section;

        return $section;
    }

    /**
     * Resolve which section of transitions.md applies for a given
     * from→to pair (and optional reason qualifier).
     */
    private function resolveBridgeSection(
        SessionState $from,
        SessionState $to,
        ?string $reason,
    ): ?string {
        // Time-threshold force-transition into CLOSING uses a special bridge
        // regardless of which state the session was in. The user must not
        // be told about the time limit.
        if ($reason === 'time_threshold' && $to === SessionState::CLOSING) {
            return 'any_to_closing_time_threshold';
        }

        // RISK_TRIAGE is a pass-through state — never bridges. CRISIS_INTERVENTION
        // is a trap state — bridges would be inappropriate there.
        if ($from === SessionState::RISK_TRIAGE ||
            $to === SessionState::RISK_TRIAGE ||
            $from === SessionState::CRISIS_INTERVENTION ||
            $to === SessionState::CRISIS_INTERVENTION) {
            return null;
        }

        $key = "{$from->value}_to_{$to->value}";

        // Whitelisted normal-flow bridges. Other pairs return null and the
        // coach prompt for the new state takes over unaided.
        $known = [
            'intake_to_exploration',
            'exploration_to_deepening',
            'deepening_to_problem_solving',
            'problem_solving_to_closing',
        ];

        return in_array($key, $known, true) ? $key : null;
    }

    /**
     * Pull a single `## Bridge: <key>` section out of transitions.md.
     */
    private function extractBridgeSection(string $document, string $sectionKey): string
    {
        $headingPattern = '/^##\s+Bridge:\s+' . preg_quote($sectionKey, '/') . '\s*$/m';

        if (preg_match($headingPattern, $document, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $start = $matches[0][1] + strlen($matches[0][0]);
        $tail = substr($document, $start);

        // The next `##` heading marks the end of the section. If absent, the
        // section runs to the end of the document.
        if (preg_match('/^##\s+/m', $tail, $next, PREG_OFFSET_CAPTURE) === 1) {
            $tail = substr($tail, 0, $next[0][1]);
        }

        // Drop the trailing `---` separator if present.
        $tail = preg_replace('/\n---\s*$/', '', $tail) ?? $tail;

        return trim($tail);
    }

    /**
     * Map SessionState to prompt file name.
     */
    private function mapStateToPromptName(SessionState $state): string
    {
        return match ($state) {
            SessionState::INTAKE => 'system', // Use system prompt for intake
            SessionState::EXPLORATION => 'exploration',
            SessionState::DEEPENING => 'deepening',
            SessionState::PROBLEM_SOLVING => 'technique',
            SessionState::CLOSING => 'closing',
            SessionState::CRISIS_INTERVENTION => 'crisis', // Falls back to global
            default => 'system',
        };
    }

    /**
     * Load a file from either override path or package path.
     */
    private function loadFile(string $relativePath): string
    {
        // Try override path first
        if ($this->overridePath !== null) {
            $overrideFile = $this->overridePath . '/' . $relativePath;
            if (file_exists($overrideFile)) {
                $content = file_get_contents($overrideFile);
                if ($content !== false) {
                    return $this->parsePrompt($content);
                }
            }
        }

        // Fall back to package path
        $packageFile = $this->packagePath . '/' . $relativePath;
        if (file_exists($packageFile)) {
            $content = file_get_contents($packageFile);
            if ($content !== false) {
                return $this->parsePrompt($content);
            }
        }

        // If file doesn't exist, return empty string (allows graceful fallback)
        return '';
    }

    /**
     * Parse a prompt file, extracting content and handling metadata.
     */
    private function parsePrompt(string $content): string
    {
        // Remove YAML front matter if present
        if (str_starts_with($content, '---')) {
            $parts = preg_split('/^---\s*$/m', $content, 3);
            if ($parts !== false && count($parts) >= 3) {
                $content = $parts[2];
            }
        }

        return trim($content);
    }

    /**
     * Check if a prompt file exists.
     */
    public function exists(string $relativePath): bool
    {
        if ($this->overridePath !== null) {
            $overrideFile = $this->overridePath . '/' . $relativePath;
            if (file_exists($overrideFile)) {
                return true;
            }
        }

        $packageFile = $this->packagePath . '/' . $relativePath;
        return file_exists($packageFile);
    }

    /**
     * Clear the prompt cache.
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Set a custom override path.
     */
    public function setOverridePath(?string $path): void
    {
        $this->overridePath = $path;
        $this->clearCache();
    }

    /**
     * Get available prompt files for a coach.
     *
     * @return array<string>
     */
    public function getAvailablePrompts(CoachId $coach): array
    {
        $coachDir = strtolower($coach->value);
        $prompts = [];

        // Check package path
        $packageDir = $this->packagePath . '/coaches/' . $coachDir;
        if (is_dir($packageDir)) {
            $files = glob($packageDir . '/*.md');
            if ($files !== false) {
                foreach ($files as $file) {
                    $prompts[] = pathinfo($file, PATHINFO_FILENAME);
                }
            }
        }

        return array_unique($prompts);
    }
}
