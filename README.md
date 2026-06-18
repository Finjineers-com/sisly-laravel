<p align="center">
  <img src="https://via.placeholder.com/200x80?text=SISLY" alt="Sisly Logo" width="200"/>
</p>

<h1 align="center">Sisly</h1>

<p align="center">
  <strong>AI Emotional Coaching for Laravel</strong><br>
  Six specialized coaches, parallel safety classifier, content prescription handoff, and full Arabic/Gulf dialect support for the GCC market.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php" alt="PHP 8.2+"/>
  <img src="https://img.shields.io/badge/Laravel-10%20|%2011%20|%2012-FF2D20?logo=laravel" alt="Laravel 10 | 11 | 12"/>
  <img src="https://img.shields.io/badge/License-Proprietary-blue" alt="License"/>
</p>

---

## Overview

Sisly is a Laravel package that provides AI-powered emotional coaching following the **Sisly method**: a primed opening → understand and validate → detect mood → hand off with a content prescription → keep talking. The chat only ends on a crisis — the content recommendation never ends it.

### Architecture

```
User message
    │
    ├─► [1] DETERMINISTIC crisis keyword check (always, before any LLM call)
    │         └─ flagged → CrisisHandler (no LLM, hard-coded response + resources)
    │
    ├─► [2] PARALLEL calls:
    │         ├─ Safety classifier (cheap model, SAFETY_SYS prompt) → SafetyVerdict
    │         └─ Coach (full model, SHARED_SPINE + PERSONA) → coach_text [+ ```sisly block]
    │
    ├─► Safety verdict override: if flagged → discard coach output → CrisisHandler
    │
    ├─► Parse ```sisly block (prescription) if present
    │
    └─► Return { safety, coach_text, prescription, ended }
```

### Key Features

- **6 Coaches** — MEETLY 📅 (meetings), VENTO 💬 (anger), LOOPY 🧠 (overthinking), PRESSO ⏳ (overwhelm), BOOSTLY ⚡ (self-doubt), SAFEO 🧭 (uncertainty)
- **Two-Tier Safety** — Deterministic keyword lexicon (pre-LLM) + LLM safety classifier (parallel)
- **Content Prescription** — Coach emits a ` ```sisly ``` ` block at handoff; package parses and optionally resolves to your content library
- **Two-Tier Models** — Coach model (full quality) + Safety/Dispatcher model (cheap/fast)
- **Arabic/Gulf Support** — Single-language mode; auto-detects EN/AR from first message
- **LLM Failover** — Circuit-breaker with automatic fallback across providers
- **Session Management** — Persistent sessions with Cache/Redis
- **Chain of Empathy** — Internal reasoning framework (CoE) that informs responses

---

## Installation

```bash
composer require sisly/sisly-laravel
php artisan vendor:publish --tag=sisly-config
```

### Environment Variables

```env
# Provider (anthropic recommended for this use case)
SISLY_LLM_DRIVER=anthropic

# Anthropic — coach model + safety model (cheaper tier)
ANTHROPIC_API_KEY=sk-ant-your-key
ANTHROPIC_MODEL=claude-sonnet-4-5
ANTHROPIC_SAFETY_MODEL=claude-haiku-4-5

# OpenAI (failover)
OPENAI_API_KEY=sk-your-openai-key
OPENAI_MODEL=gpt-4o
OPENAI_SAFETY_MODEL=gpt-4o-mini

# Gemini (failover)
GEMINI_API_KEY=your-gemini-key
GEMINI_MODEL=gemini-1.5-pro
GEMINI_SAFETY_MODEL=gemini-1.5-flash
```

---

## Quick Start

### 1. Primed Opening (Phase 1, no model call)

The spec says the coach's opening is pre-written — no backend call until the user types.

```php
use Sisly\Facades\Sisly;
use Sisly\Enums\CoachId;

// Server-side primed opening (initSession)
$response = Sisly::initSession([
    'coach_id' => 'meetly',
    'preferences' => ['language' => 'en'],
]);
echo $response->responseText;
// "Hi, I'm Meetly. Big meeting on your mind? Let's get you steady. ..."

$sessionId = $response->sessionId;

// Or get the opening text from the enum directly (client-side)
echo CoachId::MEETLY->primedOpeningEn();
```

### 2. User Sends First Message

```php
$response = Sisly::startSession(
    message: "I have a presentation in 20 minutes and my hands are shaking",
    context: [
        'coach_id' => 'meetly',          // or let the Dispatcher pick
        'preferences' => ['language' => 'en'],
        'geo' => ['country' => 'AE'],
    ]
);

$sessionId = $response->sessionId;

echo $response->responseText;
// "Big meeting energy — that's your body getting ready for something that matters.
//  Is it about being judged, or more about blanking on what to say?"

echo $response->safetyVerdict->verdict;  // "ok"
var_dump($response->prescription);       // null (not handoff turn yet)
echo $response->sessionComplete;         // false
```

### 3. Conversation Turn

```php
$response = Sisly::message($sessionId, "I'm scared they'll judge my ideas");

echo $response->responseText;
// "That fear of judgment makes total sense before a big moment. ..."

// Check safety badge
echo $response->safetyVerdict->verdict; // "ok" | "checking" | "flagged"
```

### 4. Handoff Turn (prescription block)

```php
// After 3-4 turns, the coach hands off with a prescription
$response = Sisly::message($sessionId, "I feel a bit more ready but still jittery");

if ($response->prescription !== null) {
    echo $response->prescription->contentType; // "Meditation"
    echo $response->prescription->currentMood; // "Anxious"
    echo $response->prescription->targetMood;  // "Calm"
    echo $response->prescription->reason;      // "A quick grounding before you walk in."
    echo $response->prescription->assetId;     // "lib_xyz" (if AssetResolver is wired)
    echo $response->prescription->assetUrl;    // "https://..." (if AssetResolver is wired)
}

// Chat stays open — user can continue after the prescription
echo $response->sessionComplete; // false
```

### 5. Crisis Response

```php
$response = Sisly::message($sessionId, "I want to end my life");

echo $response->safetyVerdict->verdict;   // "flagged"
echo $response->crisis->detected;         // true
echo $response->sessionComplete;          // true (ended: true in spec)
echo $response->responseText;
// "I hear that you're going through something really difficult right now.
//  Your safety matters deeply. Please reach out to UAE HOPE line 800 4673..."
```

---

## Response Object

```php
$response->sessionId          // Unique session identifier
$response->coachId            // CoachId enum
$response->coachName          // "MEETLY" etc
$response->responseText       // Coach message (coach_text in spec)
$response->safetyVerdict      // SafetyVerdict — verdict, category, rationale
$response->prescription       // Prescription|null — content handoff (null until handoff turn)
$response->sessionComplete    // bool (ended in spec)
$response->state              // SessionState enum
$response->turnCount          // int
$response->crisis             // CrisisInfo — detected, severity, category
$response->handoffSuggested   // string|null — coach handoff suggestion
$response->coeTrace           // CoETrace|null (only when includeCoETrace=true)
```

---

## Wiring Your Content Library

```php
use Sisly\Contracts\AssetResolverInterface;
use Sisly\DTOs\Prescription;

class MyAssetResolver implements AssetResolverInterface
{
    public function resolve(Prescription $prescription, string $locale): ?Prescription
    {
        // Query your library filtered by content_type + locale + mood pair
        $asset = MyLibrary::find(
            contentType: $prescription->contentType,
            locale: $locale,
            currentMood: $prescription->currentMood,
            targetMood: $prescription->targetMood,
        );

        if ($asset === null) {
            return null; // Coach will try a different content_type next turn
        }

        return new Prescription(
            contentType: $prescription->contentType,
            currentMood: $prescription->currentMood,
            targetMood: $prescription->targetMood,
            reason: $prescription->reason,
            assetId: $asset->id,
            assetUrl: $asset->streamingUrl,
        );
    }
}
```

```php
// config/sisly.php
'prescription' => [
    'resolve_assets' => true,
    'asset_resolver' => MyAssetResolver::class,
    'locale_strict' => true, // Never recommend English asset in Arabic chat
],
```

---

## Coaches

| Coach | Emoji | Focus | Primed Opening (EN) |
|-------|-------|-------|---------------------|
| **MEETLY** | 📅 | Meeting & presentation anxiety | "Hi, I'm Meetly. Big meeting on your mind?..." |
| **VENTO** | 💬 | Anger & frustration release | "Hi, I'm Vento. Sometimes you just need to get it out..." |
| **LOOPY** | 🧠 | Rumination & overthinking | "Hi, I'm Loopy. When the mind won't stop spinning..." |
| **PRESSO** | ⏳ | Work pressure & overwhelm | "Hey, I'm Presso. When it's all too much at once..." |
| **BOOSTLY** | ⚡ | Self-doubt & imposter feelings | "Hey, I'm Boostly. Running on empty?..." |
| **SAFEO** | 🧭 | Uncertainty & big decisions | "Hi, I'm Safeo. When things feel uncertain..." |

---

## Safety

### Two Layers

1. **Deterministic keyword lexicon** — runs before any LLM call; never disabled
2. **LLM safety classifier** — runs in parallel with the coach; uses the cheap safety model tier

### Crisis Response Format

```json
{
  "safety": { "verdict": "flagged", "category": "self_harm" },
  "coach_text": "I hear that you're in a lot of pain right now. ...",
  "prescription": null,
  "ended": true
}
```

### GCC Crisis Resources

| Country | Emergency | Crisis Hotline |
|---------|-----------|----------------|
| UAE | 999 | 800-HOPE (4673) |
| Saudi Arabia | 911 | 920033360 |
| Kuwait | 112 | 24SEK7-1111 |
| Bahrain | 999 | 1766 |
| Qatar | 999 | 16000 |
| Oman | 9999 | 1212 |

> ⚠️ **HARD GATE**: The crisis copy and helpline numbers in the package are defaults. Replace with clinically-signed-off copy and verified UAE helplines before any real users. See `HARD_GATES.md`.

---

## Configuration

See `config/sisly.php` for all options. Key sections:

```php
'llm' => [
    'driver' => 'anthropic',   // Primary provider
    'anthropic' => [
        'model' => 'claude-sonnet-4-5',         // Coach model
        'safety_model' => 'claude-haiku-4-5',   // Safety/dispatcher model
    ],
],
'safety_classifier' => [
    'parallel_enabled' => true,
    'fail_closed_verdict' => 'checking',        // Never crash on parse error
],
'fsm' => [
    'end_on_terminal_state' => false,  // Chat stays open after prescription
    'max_total_turns' => 40,
],
'prescription' => [
    'resolve_assets' => false,      // Set true + wire AssetResolver
    'locale_strict' => true,        // Never mix locales
],
'language' => [
    'auto_detect' => true,          // Detect EN/AR from first message
],
'telemetry' => [
    'enabled' => true,
    'log_message_content' => false, // NEVER log raw messages (privacy)
],
```

---

## Hard Gates (do not ship to real users until all green)

- [ ] Qualified mental-health professional sign-off on coach prompts AND SAFETY_SYS in **EN and AR**
- [ ] Verified, current UAE crisis helpline number + routing copy locked in both languages
- [ ] Arabic-language safety red-team on Gulf dialect and code-switched samples
- [ ] Native GCC Arabic copywriter authored persona openings in Arabic (not translations)
- [ ] Content library has at least one asset per `content_type` per `locale`
- [ ] Patent provisional + FTO confirmed before public disclosure

---

## Events

```php
use Sisly\Events\{SessionStarted, MessageReceived, ResponseGenerated,
                  StateTransitioned, SessionEnded, CrisisDetected, LLMFailoverOccurred};

Event::listen(CrisisDetected::class, function ($event) {
    Log::critical('Crisis detected', [
        'session_id' => $event->sessionId,
        'category'   => $event->category,
        'severity'   => $event->severity,
        // NOTE: message content is NOT on this event (privacy)
    ]);
});
```

---

## License

Proprietary software. See [LICENSE](LICENSE).

---

<p align="center">
  Built with care for the GCC market<br>
  <strong>Sisly</strong> — Your AI Emotional Coach
</p>
