# HARD GATES — Sisly Pre-Launch Checklist

**None of these items are negotiable before real users interact with the coaches.**

---

## Safety & Legal

- [ ] **Mental-health professional sign-off** on ALL coach prompts and the `SAFETY_SYS` classifier prompt, in **both English and Arabic**
- [ ] **Verified UAE crisis resources**: confirmed helpline number(s) and exact routing copy in EN + AR. The package default `800-4673 (HOPE) / 999` is a placeholder — replace before launch
- [ ] **Arabic safety red-team**: stress-test the safety classifier on Gulf dialect and code-switched (Arabic/English mixed) phrasing common in GCC workplaces. This is the highest-risk localisation gap.
- [ ] **Risk taxonomy signed off**: confirm which inputs map to `flagged` vs `checking` vs `ok` so the classifier can be regression-tested against real cases

## Language & Content

- [ ] **Native GCC Arabic copywriter** has authored all six coach personas and primed openings in Arabic — not machine-translated from English. The Arabic strings in the package are Claude-drafted placeholders for flow testing only.
- [ ] **Content library mapped**: at least one asset per `content_type` per `locale` so prescriptions always resolve. `content_types = [Meditation, DoWithMe, Affirmation, Sound, Podcast]` × `locales = [en, ar]` = 10 minimum.
- [ ] **Locale-strict confirmed**: prescriptions in Arabic sessions MUST resolve to Arabic assets. Set `config('sisly.prescription.locale_strict') = true`.

## Engineering

- [ ] **API key on backend only**: never in mobile binaries, web bundles, or committed `.env` files
- [ ] **TLS on `/coach/message`** with per-user auth on every call
- [ ] **Rate limiting**: e.g. 30 messages/minute per user to prevent cost runaways
- [ ] **Privacy review**: raw message content must not be persisted beyond what's needed for the turn. `config('sisly.telemetry.log_message_content') = false` is already the default — verify your log aggregator is not capturing full request bodies.

## Legal

- [ ] **Patent provisional + FTO position confirmed** with UAE/PCT attorney before any public disclosure. The two method claims (selection-as-context pre-emptive initiation; mood-bridging prescription) should not be disclosed publicly before this.

---

## Reference

- [Developer Execution Guide](../docs/INTEGRATION.md) — full build order and test matrix
- [Configuration](../docs/CONFIGURATION.md) — all config options
- Crisis resources: `resources/data/crisis_resources.json`
- SAFETY_SYS prompt: `src/Safety/SafetyClassifier.php`
