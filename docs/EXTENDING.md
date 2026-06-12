# Extending Guide

This guide explains how to extend Sisly with custom coaches, LLM providers, and session stores.

## Table of Contents

- [Custom Coaches](#custom-coaches)
- [Custom LLM Providers](#custom-llm-providers)
- [Custom Session Stores](#custom-session-stores)
- [Custom Crisis Resources](#custom-crisis-resources)
- [Customizing Prompts](#customizing-prompts)

---

## Custom Coaches

### Creating a Custom Coach

```php
// app/Coaching/Coaches/GratitudeCoach.php
namespace App\Coaching\Coaches;

use Sisly\Coaches\BaseCoach;
use Sisly\Contracts\CoachInterface;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

class GratitudeCoach extends BaseCoach implements CoachInterface
{
    /**
     * Unique identifier for this coach.
     */
    public function getId(): CoachId
    {
        // For custom coaches, you can use an existing CoachId
        // or extend the enum if needed
        return CoachId::MEETLY; // Replace with your own enum value
    }

    /**
     * Display name.
     */
    public function getName(): string
    {
        return 'Gratitude Coach';
    }

    /**
     * Brief description of what this coach handles.
     */
    public function getDescription(): string
    {
        return 'Cultivating gratitude and positive mindset';
    }

    /**
     * Short, per-language role description used in the deterministic
     * identity reply ("I'm GRATITUDE, the coach for ..."). Required for
     * every coach since v1.2.0 — must be localized for at least 'en' and 'ar'.
     */
    public function getRoleDescription(string $language): string
    {
        return match ($language) {
            'ar' => 'مدربتك لتنمية الامتنان',
            default => 'the coach for cultivating gratitude',
        };
    }

    /**
     * System prompt for the coach at a given state.
     */
    public function getSystemPrompt(SessionState $state): string
    {
        return 'You are GRATITUDE, a coach specializing in cultivating appreciation and positive mindset.';
    }

    /**
     * State-specific prompt.
     */
    public function getStatePrompt(SessionState $state): string
    {
        return match ($state) {
            SessionState::INTAKE => $this->getIntakePrompt(),
            SessionState::EXPLORATION => $this->getExplorationPrompt(),
            SessionState::DEEPENING => $this->getDeepeningPrompt(),
            SessionState::PROBLEM_SOLVING => $this->getProblemSolvingPrompt(),
            SessionState::CLOSING => $this->getClosingPrompt(),
            default => '',
        };
    }

    /**
     * Emotional domains this coach handles.
     */
    public function getDomains(): array
    {
        return ['gratitude', 'appreciation', 'positive-mindset'];
    }

    /**
     * Keywords that trigger routing to this coach.
     */
    public function getTriggers(): array
    {
        return [
            'grateful', 'thankful', 'appreciate', 'gratitude',
            'positive', 'blessings', 'fortunate',
        ];
    }

    private function getIntakePrompt(): string
    {
        return <<<'PROMPT'
You are GRATITUDE, a coach specializing in cultivating appreciation and positive mindset.

Your approach:
1. Warmly welcome the user
2. Acknowledge their desire to focus on gratitude
3. Ask what prompted them to seek a more grateful perspective today

Keep your response warm, encouraging, and under 3 sentences.
PROMPT;
    }

    private function getExplorationPrompt(): string
    {
        return <<<'PROMPT'
Continue exploring with the user:
1. Ask about recent moments of appreciation they've experienced
2. Help them identify patterns in what they're grateful for
3. Gently explore any barriers to feeling grateful

Be curious and supportive. Use reflective listening.
PROMPT;
    }

    // ... implement other state prompts similarly
}
```

### Registering the Custom Coach

```php
// app/Providers/AppServiceProvider.php
use App\Coaching\Coaches\GratitudeCoach;
use Sisly\Coaches\CoachRegistry;
use Sisly\Coaches\PromptLoader;
use Sisly\CoE\CoEEngine;
use Sisly\Contracts\LLMProviderInterface;

public function boot(): void
{
    $this->app->afterResolving(CoachRegistry::class, function (CoachRegistry $registry) {
        $coach = new GratitudeCoach(
            llm: app(LLMProviderInterface::class),
            promptLoader: app(PromptLoader::class),
            coeEngine: app(CoEEngine::class),
        );

        $registry->register($coach);
    });
}
```

### Adding to Enabled Coaches

```php
// config/sisly.php
'coaches' => [
    'default' => 'meetly',
    'enabled' => ['meetly', 'vento', 'loopy', 'presso', 'boostly', 'gratitude'],
],
```

---

## Custom LLM Providers

### Implementing LLMProviderInterface

```php
// app/Coaching/Providers/AnthropicProvider.php
namespace App\Coaching\Providers;

use Sisly\Contracts\LLMProviderInterface;
use Sisly\LLM\LLMResponse;
use Illuminate\Support\Facades\Http;

class AnthropicProvider implements LLMProviderInterface
{
    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct(array $config = [])
    {
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'claude-3-sonnet-20240229';
        $this->timeout = $config['timeout'] ?? 30;
    }

    public function getName(): string
    {
        return 'anthropic';
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    public function generate(string $prompt, array $options = []): LLMResponse
    {
        return $this->chat(
            [['role' => 'user', 'content' => $prompt]],
            '',
            $options
        );
    }

    public function chat(array $messages, string $systemPrompt = '', array $options = []): LLMResponse
    {
        if (!$this->isAvailable()) {
            return LLMResponse::failure('Anthropic provider not configured');
        }

        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => $options['max_tokens'] ?? 150,
                    'system' => $systemPrompt ?? '',
                    'messages' => $messages,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $content = $data['content'][0]['text'] ?? '';

                return LLMResponse::success(
                    content: $content,
                    promptTokens: $data['usage']['input_tokens'] ?? 0,
                    completionTokens: $data['usage']['output_tokens'] ?? 0,
                    model: $this->model
                );
            }

            return LLMResponse::failure('Anthropic error: ' . $response->body());
        } catch (\Throwable $e) {
            return LLMResponse::failure('Anthropic exception: ' . $e->getMessage());
        }
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }
}
```

### Registering the Provider

```php
// app/Providers/AppServiceProvider.php
use App\Coaching\Providers\AnthropicProvider;
use Sisly\LLM\LLMManager;
use Sisly\Contracts\LLMProviderInterface;

public function register(): void
{
    $this->app->extend(LLMProviderInterface::class, function ($manager) {
        if ($manager instanceof LLMManager) {
            $anthropic = new AnthropicProvider([
                'api_key' => config('services.anthropic.api_key'),
                'model' => config('services.anthropic.model', 'claude-3-sonnet-20240229'),
            ]);

            if ($anthropic->isAvailable()) {
                $manager->addProvider($anthropic);
            }
        }

        return $manager;
    });
}
```

---

## Custom Session Stores

### Implementing SessionStoreInterface

```php
// app/Coaching/Stores/DatabaseSessionStore.php
namespace App\Coaching\Stores;

use Sisly\Contracts\SessionStoreInterface;
use Sisly\DTOs\Session;
use App\Models\CoachingSession;

class DatabaseSessionStore implements SessionStoreInterface
{
    private string $prefix;
    private int $ttl;

    public function __construct(array $config = [])
    {
        $this->prefix = $config['prefix'] ?? 'sisly:';
        $this->ttl = $config['ttl'] ?? 1800;
    }

    public function get(string $sessionId): ?Session
    {
        $record = CoachingSession::where('session_id', $this->prefix . $sessionId)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return null;
        }

        return Session::fromArray(json_decode($record->data, true));
    }

    public function save(Session $session): void
    {
        CoachingSession::updateOrCreate(
            ['session_id' => $this->prefix . $session->id],
            [
                'data' => json_encode($session->toArray()),
                'expires_at' => now()->addSeconds($this->ttl),
            ]
        );
    }

    public function delete(string $sessionId): void
    {
        CoachingSession::where('session_id', $this->prefix . $sessionId)->delete();
    }

    public function exists(string $sessionId): bool
    {
        return CoachingSession::where('session_id', $this->prefix . $sessionId)
            ->where('expires_at', '>', now())
            ->exists();
    }
}
```

### Migration

```php
// database/migrations/2024_01_01_000000_create_coaching_sessions_table.php
Schema::create('coaching_sessions', function (Blueprint $table) {
    $table->id();
    $table->string('session_id')->unique();
    $table->json('data');
    $table->timestamp('expires_at');
    $table->timestamps();

    $table->index('expires_at');
});
```

### Registering the Store

```php
// app/Providers/AppServiceProvider.php
use App\Coaching\Stores\DatabaseSessionStore;
use Sisly\Contracts\SessionStoreInterface;

public function register(): void
{
    $this->app->singleton(SessionStoreInterface::class, function ($app) {
        return new DatabaseSessionStore([
            'prefix' => config('sisly.session.prefix'),
            'ttl' => config('sisly.session.ttl'),
        ]);
    });
}
```

---

## Custom Crisis Resources

### Creating Custom Resources

```json
// resources/sisly/crisis_resources.json
{
  "countries": {
    "US": {
      "name": "United States",
      "emergency_number": "911",
      "hotlines": [
        {
          "name": "National Suicide Prevention Lifeline",
          "number": "988",
          "available": "24/7"
        },
        {
          "name": "Crisis Text Line",
          "number": "Text HOME to 741741",
          "available": "24/7"
        }
      ]
    }
  }
}
```

### Configuration

```php
// config/sisly.php
'crisis_resources' => [
    'use_package_defaults' => false,
    'custom_path' => resource_path('sisly/crisis_resources.json'),
],
```

---

## Customizing Prompts

### Override Default Prompts

```bash
php artisan vendor:publish --tag=sisly-prompts
```

This publishes prompts to `resources/sisly/prompts/`.

### Prompt Structure

```
resources/sisly/prompts/
├── global/
│   ├── rules.md
│   └── dispatcher.md
├── meetly/
│   ├── system.md
│   ├── exploration.md
│   ├── deepening.md
│   ├── technique.md
│   └── closing.md
├── vento/
│   └── ... (same structure)
├── loopy/
│   └── ... (same structure)
├── presso/
│   └── ... (same structure)
├── boostly/
│   └── ... (same structure)
└── custom-coach/
    └── ... (your custom prompts)
```

### Configuration

```php
// config/sisly.php - automatically detected
'prompts' => [
    'override_path' => resource_path('sisly/prompts'),
],
```

### Prompt Variables

Prompts support variable substitution:

```markdown
# system.md

You are {{coach_name}}, a coach specializing in {{focus}}.

Current state: {{state}}
Turn: {{turn}} of {{max_turns}}
```

---

## Best Practices

### 1. Follow the Interface Contracts

Always implement the full interface to ensure compatibility:

```php
// Good
class MyCoach extends BaseCoach implements CoachInterface
{
    public function getId(): CoachId { ... }
    public function getName(): string { ... }
    public function getDescription(): string { ... }
    public function getSystemPrompt(SessionState $state): string { ... }
    public function getStatePrompt(SessionState $state): string { ... }
    public function getDomains(): array { ... }
    public function getTriggers(): array { ... }
    // process() is inherited from BaseCoach
}
```

### 2. Handle Errors Gracefully

The `BaseCoach::process()` method handles LLM failures automatically with fallback responses. For custom LLM providers, handle errors gracefully:

```php
public function chat(array $messages, string $systemPrompt = '', array $options = []): LLMResponse
{
    try {
        // Your LLM call here
        return LLMResponse::success($content, $promptTokens, $completionTokens, $model);
    } catch (\Throwable $e) {
        Log::error('LLM error', ['provider' => $this->getName(), 'error' => $e->getMessage()]);
        return LLMResponse::failure('An error occurred. Please try again.');
    }
}
```

### 3. Test Your Extensions

```php
class GratitudeCoachTest extends TestCase
{
    public function test_responds_to_gratitude_keywords(): void
    {
        $coach = new GratitudeCoach($this->mockLlm, $this->promptLoader);

        $triggers = $coach->getTriggers();

        $this->assertContains('grateful', $triggers);
        $this->assertContains('thankful', $triggers);
    }
}
```

### 4. Document Your Extensions

Include inline documentation:

```php
/**
 * Gratitude Coach - helps users cultivate appreciation and positive mindset.
 *
 * Trigger keywords: grateful, thankful, appreciate, gratitude, positive
 *
 * Techniques used:
 * - Three Good Things exercise
 * - Gratitude journaling prompts
 * - Reframing negative situations
 *
 * @see https://internal-docs/coaches/gratitude
 */
class GratitudeCoach extends BaseCoach
```

---

## Next Steps

- [Configuration Guide](CONFIGURATION.md) — All configuration options
- [Integration Guide](INTEGRATION.md) — API integration examples
