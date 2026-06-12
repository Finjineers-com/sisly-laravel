<p align="center">
  <img src="https://via.placeholder.com/200x80?text=SISLY" alt="Sisly Logo" width="200"/>
</p>

<h1 align="center">Sisly</h1>

<p align="center">
  <strong>AI Emotional Coaching for Laravel</strong><br>
  Five specialized coaches helping users navigate anxiety, stress, anger, overthinking, and self-doubt.
</p>

<p align="center">
  <a href="#installation">Installation</a> •
  <a href="#quick-start">Quick Start</a> •
  <a href="#coaches">Coaches</a> •
  <a href="#configuration">Configuration</a> •
  <a href="#api-reference">API Reference</a> •
  <a href="#safety">Safety</a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php" alt="PHP 8.2+"/>
  <img src="https://img.shields.io/badge/Laravel-10%20|%2011%20|%2012-FF2D20?logo=laravel" alt="Laravel 10 | 11 | 12"/>
  <img src="https://img.shields.io/badge/Tests-572%20passing-brightgreen" alt="Tests"/>
  <img src="https://img.shields.io/badge/License-Proprietary-blue" alt="License"/>
</p>

---

## Overview

Sisly is a Laravel package that provides AI-powered emotional coaching through six specialized coaches, each designed to help users with specific emotional challenges. Built for the GCC market, it includes full Arabic support with Gulf dialect translations.

### Key Features

- **6 Specialized Coaches** — MEETLY (meeting/presentation anxiety), VENTO (anger), LOOPY (overthinking), PRESSO (overwhelm), BOOSTLY (self-doubt), SAFEO (uncertainty / big decisions)
- **Safety First** — Crisis detection with deterministic keyword matching, never relies on LLM for safety
- **Arabic Support** — Full bilingual support with Gulf dialect (Khaleeji) translations
- **LLM Failover** — Automatic failover between OpenAI and Gemini providers
- **Session Management** — Persistent sessions with configurable storage (Cache/Redis)
- **Chain of Empathy** — Proprietary reasoning framework for empathetic responses
- **Finite State Machine** — Structured conversation flow from intake to closing
- **Configurable Session Length** *(v1.2.1)* — Optional wall-clock time cap (`fsm.max_session_seconds`) and graceful CLOSING wrap-up via the `fsm.end_on_terminal_state` flag. Transition bridges (`global/transitions.md`) carry context across FSM phase shifts so the bot never feels like it changed gears mid-conversation. See `CHANGELOG.md` for the recommended "engaging 10-minute" config.

---

## Installation

### Requirements

- PHP 8.2 or higher
- Laravel 10.x, 11.x, or 12.x (any supported version)
- OpenAI API key and/or Google Gemini API key

### Install via Composer

```bash
composer require sisly/sisly-laravel
```

### Publish Configuration

```bash
php artisan vendor:publish --tag=sisly-config
```

### Environment Variables

Add to your `.env` file:

```env
# Primary LLM Provider (openai or gemini)
SISLY_LLM_DRIVER=openai

# OpenAI Configuration
OPENAI_API_KEY=sk-your-openai-api-key
OPENAI_MODEL=gpt-4-turbo

# Gemini Configuration (for failover)
GEMINI_API_KEY=your-gemini-api-key
GEMINI_MODEL=gemini-pro

# Enable/Disable Failover
SISLY_LLM_FAILOVER=true
```

---

## Quick Start

### Basic Usage

```php
use Sisly\Facades\Sisly;

// Start a new coaching session (session ID is auto-generated)
$response = Sisly::startSession(
    message: "I've been feeling really anxious about my presentation tomorrow",
    context: [
        'coach' => 'meetly',      // Optional: force specific coach
        'country' => 'AE',        // Optional: for crisis resources
    ]
);

// Get the session ID from the response for subsequent calls
$sessionId = $response->sessionId;

echo $response->responseText;
// "I hear you - presentations can bring up a lot of anxiety..."

echo $response->arabicMirror;
// "أسمعك - العروض التقديمية ممكن تسبب قلق كبير..."

// Continue the conversation using the session ID
$response = Sisly::message($sessionId, "Yes, I keep thinking about everything that could go wrong");

// Check session state
$state = Sisly::getState($sessionId);
echo $state['state']; // "exploration"

// End session
Sisly::endSession($sessionId);
```

### Using Dependency Injection

```php
use Sisly\SislyManager;

class CoachingController extends Controller
{
    public function __construct(
        private SislyManager $sisly
    ) {}

    public function start(Request $request)
    {
        $response = $this->sisly->startSession(
            message: $request->input('message'),
            context: [
                'country' => $request->input('country', 'AE'),
            ]
        );

        return response()->json([
            'session_id' => $response->sessionId,
            'message' => $response->responseText,
            'arabic' => $response->arabicMirror,
            'state' => $response->state->value,
            'coach' => $response->coachName,
        ]);
    }

    public function chat(Request $request)
    {
        $response = $this->sisly->message(
            sessionId: $request->input('session_id'),
            message: $request->input('message')
        );

        return response()->json([
            'message' => $response->responseText,
            'arabic' => $response->arabicMirror,
            'state' => $response->state->value,
            'coach' => $response->coachName,
        ]);
    }
}
```

### API Endpoint Example

```php
// routes/api.php
Route::prefix('coaching')->group(function () {
    Route::post('/start', [CoachingController::class, 'start']);
    Route::post('/message', [CoachingController::class, 'message']);
    Route::get('/state', [CoachingController::class, 'state']);
    Route::post('/end', [CoachingController::class, 'end']);
});
```

---

## Coaches

Sisly includes five specialized coaches, each designed for specific emotional challenges:

| Coach | Focus | Trigger Keywords |
|-------|-------|------------------|
| **MEETLY** | Anxiety & Worry | anxious, worried, nervous, panic, fear |
| **VENTO** | Anger & Frustration | angry, frustrated, furious, irritated, mad |
| **LOOPY** | Overthinking & Rumination | overthinking, can't stop thinking, stuck in my head |
| **PRESSO** | Overwhelm & Pressure | overwhelmed, too much, can't cope, stressed |
| **BOOSTLY** | Self-Doubt & Confidence | not good enough, imposter, doubt myself, insecure |

### Automatic Coach Selection

The Dispatcher automatically routes users to the most appropriate coach based on their initial message:

```php
// User describes anxiety → routed to MEETLY
$response = Sisly::startSession("I'm so anxious about the meeting");
echo $response->coachName; // "MEETLY"

// User describes anger → routed to VENTO
$response = Sisly::startSession("I'm furious at my coworker");
echo $response->coachName; // "VENTO"
```

### Manual Coach Selection

```php
$response = Sisly::startSession(
    message: "I need help with something",
    context: ['coach' => 'presso']  // Force specific coach
);
```

---

## Session Flow

Each coaching session follows a structured flow managed by a Finite State Machine:

```
┌─────────┐     ┌─────────────┐     ┌─────────────┐     ┌──────────┐     ┌─────────────────┐     ┌─────────┐
│  INTAKE │ ──▶ │ RISK_TRIAGE │ ──▶ │ EXPLORATION │ ──▶ │ DEEPENING│ ──▶ │ PROBLEM_SOLVING │ ──▶ │ CLOSING │
└─────────┘     └─────────────┘     └─────────────┘     └──────────┘     └─────────────────┘     └─────────┘
                       │
                       │ (if crisis detected)
                       ▼
              ┌───────────────────┐
              │ CRISIS_INTERVENTION│  ◀── Safety trap state (no exit)
              └───────────────────┘
```

### State Descriptions

| State | Purpose | Turn Limit |
|-------|---------|------------|
| **Intake** | Gather initial information | 1 |
| **Risk Triage** | Safety assessment | 0 (auto) |
| **Exploration** | Understand the situation | 2 |
| **Deepening** | Explore emotions deeper | 1 |
| **Problem Solving** | Provide techniques/strategies | 3 |
| **Closing** | Wrap up session | 1 |
| **Crisis Intervention** | Safety response | ∞ (trapped) |

---

## Safety

### Crisis Detection

Sisly includes a **deterministic** crisis detection system that runs BEFORE any LLM calls:

```php
// Crisis detection is automatic - no configuration needed
$response = Sisly::startSession(
    message: "I want to end my life",
    context: ['country' => 'SA']
);

// Session immediately enters crisis state
echo $response->state->value; // "crisis_intervention"
echo $response->crisis->detected; // true

// Crisis info includes severity and category
echo $response->crisis->severity->value; // "critical"
echo $response->crisis->category->value; // "suicide"
```

### Supported Crisis Categories

- **Suicide** — Suicidal ideation or intent
- **Self-Harm** — Self-injury behaviors
- **Harm to Others** — Intent to harm others
- **Abuse** — Currently experiencing abuse
- **Medical Emergency** — Overdose, severe injury
- **Psychosis** — Signs of psychotic symptoms

### GCC Crisis Resources

Built-in crisis resources for all GCC countries:

| Country | Emergency | Crisis Hotline |
|---------|-----------|----------------|
| UAE | 999 | 800-HOPE (4673) |
| Saudi Arabia | 911 | 920033360 |
| Kuwait | 112 | 24SEK7-1111 |
| Bahrain | 999 | 1766 |
| Qatar | 999 | 16000 |
| Oman | 9999 | 1212 |

### Post-Response Validation

All LLM responses are validated before being sent to users:

```php
// Automatic validation prevents harmful content
// - Medical advice
// - Diagnostic statements
// - Directive language ("you should", "you must")
// - Clinical terminology
```

---

## Arabic Support

### Automatic Language Detection

```php
use Sisly\Arabic\LanguageDetector;

$detector = new LanguageDetector();

$detector->detect('Hello, how are you?'); // 'en'
$detector->detect('مرحبا كيف حالك');      // 'ar'
$detector->containsArabic('Hello أحمد');  // true
```

### Arabic Mirror Responses

Every response includes an Arabic translation using Gulf dialect:

```php
$response = Sisly::message($sessionId, "I feel overwhelmed");

echo $response->responseText;
// "It sounds like you have a lot on your plate right now..."

echo $response->arabicMirror;
// "يبدو إنك عندك أشياء كثيرة الحين..."
```

### Configure Dialect

```php
// config/sisly.php
'arabic' => [
    'enabled' => true,
    'dialect' => 'gulf',  // 'gulf' (Khaleeji) or 'msa' (Modern Standard Arabic)
    'mirror_enabled' => true,
],
```

---

## Configuration

### Full Configuration File

```php
// config/sisly.php
return [
    'llm' => [
        'driver' => env('SISLY_LLM_DRIVER', 'openai'),
        'failover_enabled' => env('SISLY_LLM_FAILOVER', true),
        'failure_threshold' => 5,  // Circuit breaker threshold

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4-turbo'),
            'timeout' => 30,
            'max_retries' => 3,
        ],

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-pro'),
            'timeout' => 30,
            'max_retries' => 3,
        ],
    ],

    'session' => [
        'driver' => env('SISLY_SESSION_DRIVER', 'cache'),
        'prefix' => 'sisly:session:',
        'ttl' => 1800,  // 30 minutes
    ],

    'coaches' => [
        'default' => 'meetly',
        'enabled' => ['meetly', 'vento', 'loopy', 'presso', 'boostly'],
    ],

    'safety' => [
        'crisis_detection' => true,  // Never disable in production!
        'post_response_validation' => true,
    ],

    'arabic' => [
        'enabled' => true,
        'dialect' => 'gulf',
        'mirror_enabled' => true,
    ],
];
```

See [CONFIGURATION.md](docs/CONFIGURATION.md) for detailed documentation.

---

## API Reference

### Facade Methods

```php
use Sisly\Facades\Sisly;

// Start a new session (session ID is auto-generated)
Sisly::startSession(
    string $message,
    array $context = []   // Optional: ['coach' => 'meetly', 'country' => 'AE']
): SislyResponse;

// Send a message to existing session
Sisly::message(string $sessionId, string $message): SislyResponse;

// Get current session state
Sisly::getState(string $sessionId): array;
// Returns: ['state' => 'exploration', 'turn_count' => 2, 'is_active' => true, 'coach_id' => 'meetly']

// End a session
Sisly::endSession(string $sessionId): void;

// Check if session exists
Sisly::sessionExists(string $sessionId): bool;
```

### Response Object

```php
class SislyResponse
{
    public string $sessionId;              // Unique session identifier
    public CoachId $coachId;               // Coach enum
    public string $coachName;              // Coach display name
    public string $responseText;           // English response
    public ?string $arabicMirror;          // Arabic translation
    public SessionState $state;            // Current FSM state
    public int $turnCount;                 // Current turn number
    public CrisisInfo $crisis;             // Crisis info (check $crisis->detected, ->severity, ->category)
    public ?CoETrace $coeTrace;            // Chain of Empathy reasoning trace
    public bool $sessionComplete;          // Is session finished?
    public ?string $handoffSuggested;      // Suggested coach handoff
    public DateTimeImmutable $timestamp;   // Response timestamp
}
```

### Events

```php
use Sisly\Events\SessionStarted;
use Sisly\Events\MessageReceived;
use Sisly\Events\ResponseGenerated;
use Sisly\Events\StateTransitioned;
use Sisly\Events\SessionEnded;
use Sisly\Events\CrisisDetected;
use Sisly\Events\LLMFailoverOccurred;

// Listen to events
Event::listen(CrisisDetected::class, function ($event) {
    Log::critical('Crisis detected', [
        'session_id' => $event->sessionId,
        'category' => $event->category,
        'severity' => $event->severity,
    ]);

    // Notify support team, etc.
});
```

---

## Testing

### Run Tests

```bash
# Run unit tests (default, no API keys required)
composer test

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage/

# Run specific test suite
./vendor/bin/phpunit tests/Unit/Safety/
```

### Integration Tests (Live LLM)

Integration tests make real API calls to test full conversation flows. They are **skipped by default** and only run when API keys are configured.

```bash
# 1. Copy the example environment file
cp .env.testing.example .env.testing

# 2. Add your API keys to .env.testing
# OPENAI_API_KEY=sk-your-key-here

# 3. Run integration tests
composer test:integration

# Or run with environment variable directly
OPENAI_API_KEY=sk-xxx ./vendor/bin/phpunit --testsuite Integration
```

Integration tests verify:
- Live OpenAI/Gemini API connectivity
- Full conversation flows with real LLM responses
- Multi-turn context preservation
- Arabic mirror generation
- Response quality (not generic fallbacks)

### Test with Mock Provider

```php
// In tests, use the mock provider
config(['sisly.llm.driver' => 'mock']);

// Or inject mock responses
$mock = app(MockProvider::class);
$mock->addResponse('anxiety', 'I understand you feel anxious...');
```

---

## Extending

### Custom Coaches

```php
use Sisly\Coaches\BaseCoach;
use Sisly\Contracts\CoachInterface;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

class CustomCoach extends BaseCoach implements CoachInterface
{
    public function getId(): CoachId
    {
        return CoachId::MEETLY; // Replace with your own enum value
    }

    public function getName(): string
    {
        return 'Custom Coach';
    }

    public function getDescription(): string
    {
        return 'Custom emotional support';
    }

    public function getSystemPrompt(SessionState $state): string { /* ... */ }
    public function getStatePrompt(SessionState $state): string { /* ... */ }
    public function getDomains(): array { return ['custom-domain']; }
    public function getTriggers(): array { return ['custom-keyword']; }
}

// Register in service provider
$registry = app(CoachRegistry::class);
$registry->register(new CustomCoach($llm, $promptLoader));
```

See [EXTENDING.md](docs/EXTENDING.md) for detailed documentation.

---

## Security

### Reporting Vulnerabilities

If you discover a security vulnerability, please email security@sisly.ai. Do not open a public issue.

### Security Best Practices

1. **Never disable crisis detection** in production
2. **Always validate** user input before passing to Sisly
3. **Monitor** crisis events and LLM failovers
4. **Keep API keys** in environment variables, never in code
5. **Use HTTPS** for all API communications

---

## Support

- **Documentation**: [docs/](docs/)
- **Issues**: Contact your account representative
- **Email**: support@sisly.ai

---

## License

This package is proprietary software. Unauthorized copying, modification, distribution, or use is strictly prohibited. See [LICENSE](LICENSE) for details.

---

<p align="center">
  Built with care for the GCC market<br>
  <strong>Sisly</strong> — Your AI Emotional Coach
</p>
