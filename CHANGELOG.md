# Changelog

All notable changes to `sisly/sisly-laravel` are documented here.

## [1.3.0] — 2026-06

### Overview
Major update aligning the package with the functional specification (Developer Handover Pack). All architectural decisions in the spec are now reflected in the package.

### Added

#### Parallel Safety Classifier (`SafetyClassifier`)
- New `src/Safety/SafetyClassifier.php` — runs the LLM-based safety classifier from the spec's `SAFETY_SYS` prompt
- Uses the cheaper "safety model" tier (e.g. `claude-haiku-4-5`) rather than the coach model
- Configured via `config('sisly.safety_classifier')`
- Verdict: `ok` | `checking` | `flagged`
- **Fail closed**: if response can't be parsed, falls back to `checking`
- **Flagged verdict discards coach output** and routes to crisis handler

#### Prescription / Content Handoff (`Prescription` DTO)
- New `src/DTOs/Prescription.php` — parses the ` ```sisly ``` ` block emitted by the coach at handoff
- Keys and enum values stay in English (machine contract); `reason` is in the user's language
- Validates `content_type` (Meditation/DoWithMe/Affirmation/Sound/Podcast) and moods (Excited/Happy/Calm/Anxious/Sad)
- Prescription is now returned on `SislyResponse` (`$response->prescription`)

#### Safety Verdict on Response (`SafetyVerdict` DTO)
- New `src/DTOs/SafetyVerdict.php`
- `SislyResponse` now includes `safetyVerdict` and spec-aligned `coach_text` + `ended` keys in `toArray()`

#### CoachState DTO
- New `src/DTOs/CoachState.php` — the rolling per-session state aligned to the spec memory model:
  `{ coach_id, locale, turn, situation_summary, current_mood, target_mood, last_2_messages }`
- Cross-session memory defaults to OFF (`config('sisly.coach_state.cross_session_memory') = false`)

#### Asset Resolver Interface
- New `src/Contracts/AssetResolverInterface.php` — extension point to wire in your content library
- Configure via `config('sisly.prescription.asset_resolver') => YourClass::class`
- Package calls `resolve(prescription, locale)` and enriches `asset_id` + `asset_url` on the prescription

#### Language Auto-Detection
- `LanguageDetector` is now wired in `SislyManager` — fixes PENDING_FIXES #9
- When `preferences.language` is not explicitly set, the first user message is used to auto-detect locale
- Controlled via `config('sisly.language.auto_detect')`

#### Primed Openings on `CoachId` enum
- `CoachId::primedOpeningEn()` and `CoachId::primedOpeningAr()` — Phase 1 coach openings from the spec
- `initSession()` uses these (no model call for the opening message, per spec)

#### Two-Tier Model Architecture
- `config('sisly.llm.anthropic.safety_model')` etc. — each provider now has a `safety_model` field for the cheap/fast tier
- `SafetyClassifier` and `Dispatcher` use the safety model tier
- Coach calls use the full quality model
- Recommended defaults: `claude-sonnet-4-5` (coach), `claude-haiku-4-5` (safety/dispatcher)

#### Telemetry
- Per-turn metrics logged: `coach_id`, `locale`, `turn`, `verdict`, `had_prescription`, `content_type`, `latency_ms`
- Raw message content is **never** logged (privacy pillar)
- Controlled via `config('sisly.telemetry')`

### Changed

#### `SislyManager`
- `runParallel()` — coach + safety now logically parallel (synchronous in standard PHP; swap internals for async with Octane/Swoole/Fibers)
- Identity/credential meta-questions detected **before** incrementing FSM turns — fixes **PENDING_FIXES #1**
- `end_on_terminal_state` defaults to **`false`** — "the chat only ends on a crisis verdict; the content recommendation never ends it"
- `max_history_turns` defaults to `6` (last 2 exchanges rolling window, per spec)

#### `SislyServiceProvider`
- Registers `SafetyClassifier`, `LanguageDetector`, `AssetResolverInterface`
- `Dispatcher` now uses the safety model tier (classification only, no full model needed)
- Two `createLLMProvider()` / `createSafetyProvider()` methods for each tier

#### `SessionPreferences`
- `arabicMirror` defaults to **`false`** (strict single-language mode)
- Docblock deprecation notice added per PENDING_FIXES #10

#### `CoachId` enum
- Added `emoji()`, `primedOpeningEn()`, `primedOpeningAr()` methods

#### `BaseCoach`
- `process()` now returns `prescription` key in result array
- Prescription parsing delegated to `Prescription::fromSislyBlock()`

#### `config/sisly.php`
- Added `safety_classifier`, `coach_state`, `language`, `prescription`, `dispatcher`, `telemetry` sections
- Two-tier model config per provider
- `end_on_terminal_state` default changed to `false`
- `max_history_turns` default changed to `6`

### Fixed
- **PENDING_FIXES #1** — Identity/credential questions no longer consume FSM state turns
- **PENDING_FIXES #9** — `LanguageDetector` now wired and used for auto-detection

### Deprecated
- `SislyResponse::$arabicMirror` — always `null` for coaching; still used in crisis responses. Will be removed in v2.0. Use `$response->responseText` (single-language per user's locale).

---

## [1.2.1] — 2026-04

### Added
- `fsm.max_session_seconds` (opt-in wall-clock cap)
- `fsm.end_on_terminal_state` flag
- `fsm.nearing_end_threshold` for graceful CLOSING transition
- Transition bridges (`global/transitions.md`) carried across FSM phase shifts
- `session.max_history_turns` bumped to 40 for longer session coherence
- `initSession()` — coach-initiated greeting without a user message
- Per-language greetings on each coach (`getGreeting(language)`)
- `IdentityQuestionDetector` + `CredentialQuestionDetector` deterministic short-circuits

### Changed
- Turn limits bumped: exploration 2→3, deepening 1→2, problem_solving 3→5, closing 1→2
- `max_total_turns` bumped from 20 to 40

---

## [1.2.0] — 2026-03

### Added
- SAFEO coach (uncertainty / regional tension / big decisions)
- Anthropic Claude provider (`AnthropicProvider`)
- Single-language mode (response matches user's language — no parallel Arabic mirror)

---

## [1.0.0] — 2026-01

Initial release with five coaches, crisis detection, Arabic support, LLM failover.
