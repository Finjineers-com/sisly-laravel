# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.1] - 2026-04-28

### Added
- **Wall-clock session cap** with graceful close (opt-in). New config `fsm.max_session_seconds` enforces an absolute lifetime from `Session::createdAt`. When the session has consumed `fsm.nearing_end_threshold` (default `0.85`) of its budget, `SislyManager::message()` force-transitions the FSM to `CLOSING` BEFORE generating the next response — so the bot uses the coach's `closing.md` prompt for the wrap-up rather than an abrupt cutoff. When elapsed reaches `max_session_seconds`, the session ends with new `SessionEnded::endReason = 'time_limit'` AFTER returning the user's final response.
- **Configurable LLM context window**. New config `session.max_history_turns` (default `40`, was hardcoded `20`) controls FIFO history pruning on the `Session` object. Bigger window = LLM stays coherent across longer sessions.
- **`fsm.end_on_terminal_state` flag** (default `true` = preserve v1.2.0 behaviour). When `false`, transitioning into `CLOSING` does NOT auto-end the session — the FSM stays in CLOSING for graceful multi-turn wrap-up. Recommended `false` for chat-app UX.
- **Transition bridges** — one-turn carry-over guidance prevents abrupt tone shifts between FSM phases. New `resources/prompts/global/transitions.md` (mirrored under `prompts/global/`) holds bridges for each meaningful transition pair (`intake_to_exploration`, `exploration_to_deepening`, `deepening_to_problem_solving`, `problem_solving_to_closing`) plus a special `any_to_closing_time_threshold` for the wall-clock force-close. `BaseCoach::buildFullSystemPrompt()` appends the relevant bridge for ONE TURN immediately after a transition, instructing the bot to acknowledge continuity from the previous phase before easing into the new one.
- New `PromptLoader::loadTransitionBridge(SessionState $from, SessionState $to, ?string $reason)` resolves which section of `transitions.md` applies.
- `Session` DTO gains `int $maxHistoryTurns`, `int $lastTransitionAt`, `?SessionState $lastTransitionFromState`, `?string $lastTransitionReason` properties. All serialized in `toArray`/`fromArray` with back-compat fallback defaults so v1.2.0-cached sessions deserialize unchanged.
- `Session::transitionTo()` gains an optional `?string $reason` parameter. Currently the only recognised value is `'time_threshold'` — used by `SislyManager` to flag the bridge variant for time-driven force-closes.
- New `SessionEnded::endReason` value `'time_limit'`.
- New unit tests: `SessionTest` extended with 10 v1.2.1 cases (configurable history, transition tracking, round-trip), new `tests/Unit/SislyManagerTimeCapTest.php` (7 cases for time threshold + cap + `end_on_terminal_state`), new `tests/Unit/Coaches/TransitionBridgeTest.php` (11 cases for bridge resolution + prompt-assembly integration). Test count: **769 → 796 unit tests, 1878 → 1964 assertions**, all green.

### Changed — bumped defaults (behaviour change for upgrading consumers)
Session length and engagement increase out of the box. Caps move UP, not down — strictly more conversation room.

| Config key | v1.2.0 | v1.2.1 default |
|---|---|---|
| `session.max_history_turns` (NEW) | hardcoded `20` | **`40`** |
| `fsm.max_total_turns` | `20` (= 10 cycles) | **`40`** (= 20 cycles) |
| `fsm.turn_limits.exploration` | `2` | **`3`** |
| `fsm.turn_limits.deepening` | `1` | **`2`** |
| `fsm.turn_limits.problem_solving` | `3` | **`5`** |
| `fsm.turn_limits.closing` | `1` | **`2`** |
| `fsm.max_session_seconds` (NEW) | n/a | `null` (opt-in) |
| `fsm.end_on_terminal_state` (NEW) | n/a (effective `true`) | `true` |
| `fsm.nearing_end_threshold` (NEW) | n/a | `0.85` |

Effect on a default-config consumer who upgrades:
- FSM natural-end at cycle **13** (was 7).
- Hard cap at cycle **20** (was 10).
- LLM remembers last **20 cycles** (was 10).

### Migration — keeping exact v1.2.0 behaviour
Drop this into your app's `config/sisly.php`:

```php
'session' => [
    'max_history_turns' => 20,
],
'fsm' => [
    'max_total_turns' => 20,
    'turn_limits' => [
        'intake' => 1, 'risk_triage' => 0, 'exploration' => 2,
        'deepening' => 1, 'problem_solving' => 3, 'closing' => 1,
    ],
],
```

The new opt-in flags (`max_session_seconds`, `end_on_terminal_state`, `nearing_end_threshold`) all default to no-op behaviour and never need to be set if you don't want them.

### Recommended config — "fully engaging up to 10 minutes"

```php
'session' => [
    'ttl' => 900,                      // 15 min idle (5 min buffer over the cap)
    'max_history_turns' => 60,         // ~30 cycles of LLM memory
],
'fsm' => [
    'max_session_seconds'   => 600,    // 10 min wall-clock cap
    'nearing_end_threshold' => 0.85,   // bot starts closing at 8:30
    'end_on_terminal_state' => false,  // CLOSING is livable, not a cliff
    'max_total_turns'       => 60,
    'turn_limits' => [
        'intake' => 1, 'risk_triage' => 0,
        'exploration' => 4, 'deepening' => 3,
        'problem_solving' => 8, 'closing' => 100,
    ],
],
```

## [1.2.0] - 2026-04-26

### Added
- **SAFEO coach**: Sixth coach added — handles uncertainty, regional tension, job insecurity, fear of the unknown, and big life decisions made under pressure.
  - New `Sisly\Enums\CoachId::SAFEO` enum case (additive — does not break existing matches that include a `default` arm).
  - New `Sisly\Coaches\SafeoCoach` class with full domain/triggers/greetings.
  - New prompt set under `resources/prompts/coaches/safeo/{system,exploration,deepening,technique,closing}.md` (and mirrored under `prompts/coaches/safeo/`).
  - Chain of Emotion: `Uncertainty → Anxiety → Catastrophising → Acknowledgment → Anchoring → Steadiness`.
  - Enabled by default in `config/sisly.php` `coaches.enabled`. Opt out by removing `'safeo'` from the array.
  - Dispatcher prompt (`global/dispatcher.md`) and the PHP fallback in `Dispatcher::getDefaultPrompt()` updated to route to SAFEO. Handoff prompt (`global/handoff.md`) extended with SAFEO introductions and example transitions.
  - Existing 5 coaches' "Out of Scope" handoff lists now include SAFEO cross-references.
- **Credential & human-ness guardrail** (NIST-AI-RMF / NHS-DCB0129 alignment):
  - New `Sisly\Coaches\CredentialQuestionDetector` — detects "are you a therapist?", "are you human?", "هل انت حقيقية؟" and similar EN+AR phrasings via tight anchored regex.
  - New `BaseCoach::buildHardcodedCredentialReply()` returns a deterministic reply that disclaims any clinical credential and any humanity claim. Bypasses the LLM entirely.
  - `BaseCoach::process()` now checks credential questions BEFORE identity questions so realness/credential queries always receive the disclaimer shape.
  - `BaseCoach` constructor accepts a 5th optional argument `?CredentialQuestionDetector $credentialDetector` for custom detection.
  - The FINAL OVERRIDE anchor in every coach's system prompt now explicitly bans claiming any clinical credential or humanity, as a defense-in-depth backstop.
  - `global/rules.md` gained a new highest-priority `## Credentials & Persona Boundaries` section.
- **Enriched coach personas** for all 5 existing coaches (MEETLY, VENTO, LOOPY, PRESSO, BOOSTLY) plus the new SAFEO:
  - New `## Background & Inner Orientation` section per coach: GCC roots, cultural fabric, female-coded voice, *inner orientation* informed by long experience supporting working professionals — explicitly NOT a clinical-credential claim.
  - New `## Personality` section per coach: relational tone, patience profile, when to speak vs. when to wait.
  - New `## How I Speak` section per coach: tone rules, single-language enforcement (no mid-message language weaving), one-question cadence.
  - New `## Gulf Phrasing` section per coach: 3–5 concrete Khaleeji phrases the coach can draw from when the user is writing in Arabic.
  - Strengthened `## What I Never Do` block per coach with the specific forbidden phrases for that coach (e.g., MEETLY never says "just relax", VENTO never says "calm down", LOOPY never says "stop overthinking", PRESSO never uses productivity jargon, BOOSTLY never uses hollow affirmations).
  - All coach `system.md` prompt versions bumped from `1.0` to `1.1`.
- **Regression guard tests** in `tests/Unit/Prompts/PromptsAreCleanTest.php`:
  - `test_coach_system_prompt_does_not_claim_clinical_credentials` — every coach prompt is scanned for first-person credential assertions.
  - `test_coach_system_prompt_disclaims_being_a_clinician` — every coach prompt must contain the literal phrase "AI coach".
- New unit test files: `CredentialQuestionDetectorTest`, `SafeoCoachTest`. New cases extending `HardcodedIdentityReplyTest`, `CoachGreetingsTest`, `CoachIdTest`, `CoachRegistryTest`, `PromptsAreCleanTest`.
- Integration test additions: SAFEO scenarios in `FullConversationTest::test_dispatcher_routes_to_correct_coach`, `SessionFlowTest::test_can_start_session_with_safeo_coach`, `LanguagePreferenceIntegrationTest`, and `run-conversation-test.php`.

### Changed
- `CoachId::displayName()` and `CoachId::focus()` exhaustive `match` statements extended with the SAFEO arm.
- `CoachRegistry::createCoach()` exhaustive `match` extended with the SAFEO arm.
- `config/sisly.php` `coaches.enabled` default extended from 5 to 6 entries.
- `global/rules.md` and `global/dispatcher.md` versions bumped to `1.1` to reflect the credential guardrail and the SAFEO domain entry.
- Test count: 654 → 769 unit tests passing (1668 → 1878 assertions).

### Migration / Behaviour Changes (non-breaking but worth noting)
- The `CoachId` enum gained one new case (`SAFEO`). Internal exhaustive `match` statements have all been updated. **External consumers using exhaustive `match` over `CoachId` without a `default` arm will encounter `UnhandledMatchError` until they add a `SAFEO` arm.** This is the only behaviourally observable change for downstream code.
- No method signatures changed. No config keys removed. The `BaseCoach` constructor's new optional `$credentialDetector` parameter is the 5th position; existing 4-arg callers continue to work unchanged.

### Added (carried over from prior release notes)
- **Deterministic identity replies**: Identity questions ("what's your name?", "ما اسمك؟", etc.) now bypass the LLM entirely and return a hardcoded `"I'm {COACH}, ..."` reply. Eliminates LLM-flakiness on meta-questions and saves a token round-trip.
  - New `Sisly\Coaches\IdentityQuestionDetector` — anchored EN regex + AR substring patterns, 60-char message cap to avoid false positives.
  - New abstract method `BaseCoach::getRoleDescription(string $language)` — must be implemented by every coach.
  - New `BaseCoach::buildHardcodedIdentityReply(Session)` — returns the deterministic reply with the coach name in **Latin script** in both languages (brand-consistent).
- `BaseCoach` constructor now accepts an optional 4th argument `IdentityQuestionDetector $identityDetector` for custom detection rules.
- Live integration test `LanguagePreferenceIntegrationTest` exercising `language=ar` and `arabicMirror=false` against real OpenAI.

### Changed
- `BaseCoach::buildLanguageRule()` is now the single source of truth for output language. Embedded in the final identity anchor so it lands last in the system prompt.
- `BaseCoach::buildFullSystemPrompt()` no longer emits the legacy `LANGUAGE INSTRUCTION` block — `buildLanguageRule()` supersedes it.
- AR responses now keep coach names in Latin script (e.g., "أنا MEETLY") rather than transliterating.
- `SislyManager::validateAndSanitizeResponse()` now logs blocked responses via `Log::warning` (was: `// TODO`).

### Removed
- **`Sisly\Arabic\ArabicMirrorGenerator`** class (and its test) — was unused dead code.
- "Arabic Mirror" / "Arabic Mirror Examples" / "Arabic Closing" sections from all coach prompt files (`system.md`, `exploration.md`, `deepening.md`, `closing.md`) and `global/rules.md` + `global/handoff.md`. Static "include Arabic mirror" instructions in prompts conflicted with the runtime language flag — now removed.

### Fixed
- **Coach name confusion**: coaches no longer respond as "Sisly" or ignore identity questions. Combination of prompt anchor in each `system.md` + deterministic short-circuit in `BaseCoach::process()`.
- **`arabicMirror` flag silently ignored**: `SessionPreferences.arabicMirror` now actually controls Arabic content in EN responses (was: prompts hard-coded Arabic mirror lines regardless of the flag).
- **`language='en'` not honored**: prompts no longer instruct the model to include Arabic regardless of the language preference.

### Migration / Breaking Changes
- Subclasses of `BaseCoach` MUST now implement `getRoleDescription(string $language): string` (abstract). All 5 built-in coaches updated; external custom coaches need to add this method before upgrading.

## [1.1.0] - 2026-02-26

### Added
- **Coach-Initiated Sessions**: New `initSession()` method allows coaches to send the first message
  - Each coach has a unique, domain-specific greeting
  - Greetings available in English and Arabic
  - Usage: `Sisly::initSession(['coach_id' => 'meetly', 'preferences' => ['language' => 'en']])`
- **PRESSO Coach**: Pressure and overwhelm coach — nervous system regulation, slow pacing, felt vs real urgency (21 tests)
- **VENTO Coach**: Anger and frustration release coach — safe emotional discharge, validation without fixing (21 tests)
- **LOOPY Coach**: Rumination and overthinking coach — pattern interruption, present-moment grounding (21 tests)
- **BOOSTLY Coach**: Self-doubt and imposter syndrome coach — evidence-based competence reconnection (21 tests)
- All 5 coaches now fully implemented with dedicated classes, prompt files, and test suites
- `getGreeting()` method added to `CoachInterface` contract

### Changed
- **Single-Language Responses**: Responses now generated in ONE language based on `preferences.language`
  - Set `'language' => 'en'` for English-only responses
  - Set `'language' => 'ar'` for Arabic-only responses (Gulf dialect)
  - `arabicMirror` field remains in response but is now `null` (backward compatible)
- Language instruction added to LLM system prompts for consistent output
- Test count increased from 488 to 572 (1291 assertions)
- CoachRegistry cleaned up — removed TODO comment and all temporary fallback lines

### Fixed
- **FSM State Persistence Bug**: State turn counter was stored in-memory only, causing states to never advance beyond EXPLORATION across HTTP requests
  - `stateTurns` now stored on `Session` object and persisted to session store
  - States now properly transition: INTAKE → EXPLORATION → DEEPENING → PROBLEM_SOLVING → CLOSING

## [1.0.0] - 2026-02-02

### Added

#### Core Features
- **5 Specialized Coaches**: MEETLY (anxiety), VENTO (anger), LOOPY (overthinking), PRESSO (overwhelm), BOOSTLY (self-doubt)
- **Chain of Empathy (CoE)**: Proprietary 5-step reasoning framework for empathetic responses
- **Finite State Machine**: Structured conversation flow (Intake → Risk Triage → Exploration → Deepening → Problem Solving → Closing)
- **Automatic Coach Routing**: Dispatcher analyzes user messages and routes to appropriate coach

#### Safety Layer
- **Crisis Detection**: Deterministic keyword matching for suicide, self-harm, abuse, and other crisis categories
- **Crisis Resources**: Built-in emergency contacts and hotlines for all 6 GCC countries
- **Post-Response Validation**: Filters harmful content, medical advice, and directive language
- **Crisis Trap State**: Sessions detecting crisis cannot exit crisis intervention mode

#### LLM Integration
- **OpenAI Provider**: Full support for GPT-4 and GPT-4-Turbo models
- **Gemini Provider**: Google Gemini API integration
- **LLM Manager**: Automatic failover between providers with circuit breaker pattern
- **Configurable Retry Logic**: Exponential backoff with jitter for rate limiting and errors

#### Arabic Support
- **Language Detection**: Automatic detection of Arabic vs English text
- **Arabic Mirror**: Gulf dialect (Khaleeji) translations for all responses
- **Bilingual Crisis Detection**: Keywords in both English and Arabic
- **MSA Support**: Optional Modern Standard Arabic dialect

#### Session Management
- **Session Persistence**: Cache and Redis storage adapters
- **History Management**: Automatic pruning at 20 turns
- **State Tracking**: Turn counts and state transitions per session
- **TTL Configuration**: Configurable session expiration

#### Developer Experience
- **Laravel Integration**: Service provider, facade, and config publishing
- **Comprehensive Events**: SessionStarted, MessageReceived, CrisisDetected, etc.
- **Exception Hierarchy**: Typed exceptions for all error cases
- **Mock Provider**: Full testing support without LLM API calls

#### Documentation
- **README**: Quick start guide and feature overview
- **CONFIGURATION.md**: All configuration options documented
- **INTEGRATION.md**: API integration examples with Laravel
- **EXTENDING.md**: Guide for custom coaches and providers
- **CHANGELOG.md**: Version history

### Security
- Crisis detection runs before LLM calls (deterministic, not AI-dependent)
- API keys stored in environment variables only
- Post-response validation prevents harmful content
- No personal data stored in logs

### Technical Details
- PHP 8.2+ required
- Laravel 10.x, 11.x, and 12.x supported
- 572 automated tests with 1290 assertions
- PSR-4 autoloading
- Strict types throughout

---

## Version History

| Version | Date | Highlights |
|---------|------|------------|
| 1.1.0 | 2026-02-26 | All 5 coaches, initSession(), single-language responses, FSM bug fix |
| 1.0.0 | 2026-02-02 | Initial release with MEETLY coach, safety layer, and full GCC support |

---

## Upgrade Guide

### From Beta to 1.0.0

If you were using a beta version:

1. Update composer dependency:
   ```bash
   composer require sisly/sisly-laravel:^1.0
   ```

2. Republish configuration:
   ```bash
   php artisan vendor:publish --tag=sisly-config --force
   ```

3. Review configuration changes in `config/sisly.php`

4. Clear caches:
   ```bash
   php artisan cache:clear
   php artisan config:clear
   ```

---

## Roadmap

### 1.1.0 ✅ Released
- [x] Additional coaches: PRESSO, VENTO, LOOPY, BOOSTLY full implementations
- [x] Coach-initiated sessions (`initSession()`)
- [x] Single-language responses (EN or AR based on preference)
- [x] FSM state persistence fix

### 1.2.0 (Planned)
- [ ] Coach handoff between sessions
- [ ] Analytics dashboard integration
- [ ] Webhook support for external integrations

### 1.3.0 (Planned)
- [ ] Voice input support
- [ ] Multi-language expansion (Urdu, Hindi, Tagalog for GCC expat population)
- [ ] Custom coach builder UI
- [ ] Session export/import

---

[Unreleased]: https://github.com/sisly/sisly-laravel/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/sisly/sisly-laravel/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/sisly/sisly-laravel/releases/tag/v1.0.0
