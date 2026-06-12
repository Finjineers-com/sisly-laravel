# Pending Fixes

**Created:** 2026-04-26
**Origin:** Bug-fix sweep on 2026-04-26 surfaced 24 items; 13 were fixed in-session, 11 deferred and tracked here.

This document is the durable handoff for items intentionally not addressed in the 2026-04-26 sweep. Each entry includes enough context to pick up cold — what's wrong, why it was deferred, and what the fix should look like.

---

## Conventions

- **Status:** `Deferred` (decided not to fix now), `Blocked` (needs user/external action), `Decision needed` (architectural pick before implementation).
- **Effort:** rough estimate.
- **Risk if left:** what happens if this stays open.

---

## 🐛 Correctness

### #1 — Identity questions consume an FSM turn
**Status:** Deferred
**Effort:** ~30 min
**Risk if left:** Low. A user asking "what's your name?" 1-2× per session burns 1-2 of their 20-turn cap.

**What's wrong**
`SislyManager::message()` (lines 271-274) calls `addTurn(user)` and `incrementStateTurns()` *before* `processWithCoach()`. `BaseCoach::process()` then short-circuits identity questions to a hardcoded reply, but the turn was already counted. INTAKE state has a 1-turn cap, so a user who opens with "What's your name?" exhausts INTAKE on a meta question and gets pushed into EXPLORATION on their first real message.

**Where**
- `sisly-laravel/src/SislyManager.php` — `message()` method, around line 271
- `sisly-laravel/src/SislyManager.php` — `startSession()` method has the same shape

**Fix sketch**
Inject `IdentityQuestionDetector` into `SislyManager`. Detect identity at the manager level *before* incrementing turns. If detected, route through a thin path that:
1. Adds the user/assistant turn pair to history (so context is preserved)
2. Skips `incrementStateTurns()` and `addTurn(user)`-driven turn-count bump (or decrements after)
3. Skips `processWithCoach()` and calls `BaseCoach::buildHardcodedIdentityReply()` directly via `CoachRegistry`

**Verification**
New unit test: 21-turn session of identity questions ends with `state=INTAKE` and `turnCount` reflecting only real coaching turns.

---

### #2 — Identity reply bypasses post-response validation
**Status:** Deferred
**Effort:** ~10 min
**Risk if left:** Very low. The hardcoded reply is constructed from `getName()` + `getRoleDescription()` and never contains harmful content. Inconsistency only.

**What's wrong**
`BaseCoach::process()` short-circuits identity questions and returns the hardcoded reply directly. `SislyManager` then adds it to history and ships it without running it through `validateAndSanitizeResponse()`. The "real" coaching path always goes through that validator.

**Fix sketch**
In `SislyManager::message()` / `startSession()`, the response from `processWithCoach()` should always go through `validateAndSanitizeResponse()` regardless of the path it took. Already does — but if we adopt #1's "skip processWithCoach for identity questions," we'd need to keep validation in the new path. Document and don't regress.

---

## 🧹 Tech Debt / Architectural Decisions

### #4 — `SislyResponse.arabicMirror` field is always `null` for coaching
**Status:** Decision needed
**Effort:** 5 min (deprecate) / 30 min (remove + bump major) / 2 hours (revive bilingual mode)

**What's wrong**
The DTO field exists in v1.0+ for backward compatibility but always returns `null` since v1.1's "single-language mode." Crisis flow still uses it. For coaching, it's structural noise.

**Three options**
- **A. Deprecate now, remove in 2.0** — add `@deprecated since v1.2, removed in v2.0` to `src/DTOs/SislyResponse.php` property + `toArray()` key. Non-breaking. Recommended.
- **B. Remove entirely** — bumps to 2.0.0; breaks JSON contract for any consumer reading the field.
- **C. Revive bilingual mode** — populate when `language='en' && arabicMirror=true`. Requires reviving the deleted `ArabicMirrorGenerator` or generating inline. Adds an LLM call per response.

**Recommendation**: A.

---

### #9 — `Sisly\Arabic\LanguageDetector` is unused at runtime
**Status:** Decision needed
**Effort:** ~20 min (wire) / 5 min (delete)

**What's wrong**
The class detects Arabic vs English text. `BaseCoach` and `SislyManager` never call it. Only tests reference it. Two paths:

- **Wire it**: when consumer doesn't pass `preferences.language`, auto-detect from the user message. Useful for callers who don't track language client-side.
- **Delete it**: less code, but loses an opt-in convenience.

**Where**
- `sisly-laravel/src/Arabic/LanguageDetector.php`
- `sisly-laravel/tests/Unit/Arabic/LanguageDetectorTest.php`

**Recommendation**: wire it as auto-detect default (only fires when `preferences.language` is not set).

---

### #10 — `SessionPreferences.arabicMirror` flag semantics
**Status:** Decision needed
**Effort:** ~30 min if renaming/removing

**What's wrong**
Originally meant "include parallel Arabic translation in `arabic_mirror` field." Now (post-2026-04-26) it means "in EN responses, allow ONE short Arabic empathy line embedded in the body." The semantic shift isn't reflected in the name. Defaults to `true` for backward compat.

**Options**
- Keep as-is (current state — works, just slightly misleading name).
- Rename to `allowArabicEmpathyLine` in v2.0 — breaking.
- Drop the flag entirely; default everyone to "no Arabic in EN responses" — breaking, simpler API.

**Recommendation**: keep through v1.x, revisit at v2.0 with #4 together.

---

## 🧪 Test Coverage Gaps

### #12 — No end-to-end identity test through `SislyManager`
**Status:** Deferred
**Effort:** ~20 min

**What's wrong**
Identity short-circuit is tested at `BaseCoach::process()` level (unit test, MockProvider, asserts call count = 0). No test exercises the full SislyManager → CoachRegistry → BaseCoach path for an identity question.

**Fix sketch**
Add `tests/Unit/SislyManager/IdentityFlowTest.php`. Build a SislyManager with a real CoachRegistry + MockProvider; call `startSession("What's your name?", ...)`; assert response contains coach name, MockProvider call count is 0, no exception.

**Note**: depends on having real DI setup — may be easier to test in `tests/Integration/` without API keys (since it's deterministic).

---

### #13 — `arabicMirror=true` (default) path has no integration test
**Status:** Deferred
**Effort:** ~15 min

**What's wrong**
`LanguagePreferenceIntegrationTest` covers `arabicMirror=false` (zero Arabic) and `language=ar` (no English). The default `arabicMirror=true + language=en` path is unverified at the live-LLM level.

**Fix sketch**
Add test asserting: response is mostly Latin (>80% Latin chars) AND optionally contains 0-30 Arabic chars (allowing one short mirror line). Soft assertion — may be flaky depending on LLM mood.

---

### #14 — Turn-counting regression test
**Status:** Deferred (depends on #1)
**Effort:** ~15 min after #1

**What's wrong**
No test verifies that identity questions don't consume FSM turns (because they currently *do* — see #1). Add this once #1 is fixed.

---

## 🔒 Security / Ops (USER ACTION REQUIRED)

### #16 — Rotate exposed `OPENAI_API_KEY` 🔴
**Status:** Blocked — only the account owner can do this.
**Effort:** 2 min
**Risk if left:** **High.** The key in `sisly-laravel/.env.testing` was visible in this Claude session and was previously committable (it's now gitignored, but cache/history elsewhere may have captured it).

**Action**
1. Go to https://platform.openai.com/api-keys
2. Revoke the key currently in `.env.testing`
3. Create a new key, paste into `.env.testing` (still gitignored)
4. Re-run `./vendor/bin/phpunit --testsuite Integration` to verify

---

### #18 — Add `GEMINI_API_KEY` to enable failover tests 🔴
**Status:** Blocked
**Effort:** 5 min
**Risk if left:** Medium. The Gemini failover path in `LLMManager` is untested; if OpenAI goes down in production, no confidence the fallback works.

**Action**
1. Get a Gemini API key (Google AI Studio)
2. Add to `.env.testing`: `GEMINI_API_KEY=...`
3. Run `./vendor/bin/phpunit --testsuite Integration --filter GeminiProvider`

---

### #19 — No CI workflow
**Status:** Out of scope for in-session fix
**Effort:** 1-2 hours

**What's missing**
No `.github/workflows/` directory found. Tests run only locally. No automated runs on PRs.

**Fix sketch**
- `.github/workflows/test.yml` — runs `composer test` (unit suite) on every push/PR
- Optionally a separate workflow for integration tests gated behind a manual trigger to avoid burning OpenAI tokens on every push
- Set `OPENAI_API_KEY` as a repo secret if integration tests are wanted in CI

**Note**: depends on the project actually being on GitHub. Currently the repo is local-only (just initialized 2026-04-26).

---

## ⏭️ Won't Fix (Decided)

These were on the original list but resolved without code change:

- **#5** "Meta Questions" section in `rules.md` — kept as defense-in-depth for novel phrasings the regex detector might miss.
- **#11** Stale comment in `BaseCoach::process()` — re-read; it's accurate.
- **#15** Integration test gating — already gated via `requireOpenAI()` skip.
- **#17** `.gitignore` + `.env.testing.example` — already exist and are correct.
- **#22** Constructor docstring — done in earlier session.

---

## How to pick this up

Recommended order if revisiting:

1. **#16** (security, 2 min, urgent)
2. **#18** (test infra, 5 min)
3. **#1 + #14** (FSM turn-counting bug + its test, ~45 min)
4. **#4 + #10** (DTO/flag cleanup, ~10 min for option A)
5. **#9** (LanguageDetector wire/delete, ~20 min)
6. **#12, #13** (test gaps, ~35 min)
7. **#19** (CI, 1-2 hr)

Total to clear everything: ~3-4 hours.
