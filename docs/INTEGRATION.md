# Integration Guide

This guide shows how to integrate Sisly into your Laravel application with practical examples.

## Table of Contents

- [Basic Setup](#basic-setup)
- [API Integration](#api-integration)
- [WebSocket Integration](#websocket-integration)
- [Event Handling](#event-handling)
- [Error Handling](#error-handling)
- [Testing](#testing)
- [Production Considerations](#production-considerations)

---

## Basic Setup

### Service Provider

Sisly auto-registers its service provider. If you need manual registration:

```php
// config/app.php
'providers' => [
    // ...
    Sisly\SislyServiceProvider::class,
],

'aliases' => [
    // ...
    'Sisly' => Sisly\Facades\Sisly::class,
],
```

### Middleware (Optional)

Create middleware for session validation:

```php
// app/Http/Middleware/ValidateSislySession.php
namespace App\Http\Middleware;

use Closure;
use Sisly\Facades\Sisly;
use Illuminate\Http\Request;

class ValidateSislySession
{
    public function handle(Request $request, Closure $next)
    {
        $sessionId = $request->input('session_id') ?? $request->session()->getId();

        if ($request->routeIs('coaching.message') && !Sisly::sessionExists($sessionId)) {
            return response()->json([
                'error' => 'Session not found. Please start a new session.',
                'code' => 'SESSION_NOT_FOUND',
            ], 404);
        }

        $request->merge(['sisly_session_id' => $sessionId]);

        return $next($request);
    }
}
```

---

## API Integration

### Controller Example

```php
// app/Http/Controllers/CoachingController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Sisly\Facades\Sisly;
use Sisly\Exceptions\SessionNotFoundException;
use Sisly\Exceptions\InvalidMessageException;

class CoachingController extends Controller
{
    /**
     * Start a new coaching session.
     */
    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:5000',
            'coach' => 'nullable|string|in:meetly,vento,loopy,presso,boostly',
            'country' => 'nullable|string|size:2',
        ]);

        $response = Sisly::startSession(
            message: $request->input('message'),
            context: [
                'coach' => $request->input('coach'),
                'country' => $request->input('country', 'AE'),
            ]
        );

        return response()->json([
            'session_id' => $response->sessionId,
            'message' => $response->responseText,
            'arabic' => $response->arabicMirror,
            'coach' => $response->coachName,
            'state' => $response->state->value,
            'is_crisis' => $response->crisis->detected,
        ]);
    }

    /**
     * Send a message to an existing session.
     */
    public function message(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
            'message' => 'required|string|max:5000',
        ]);

        try {
            $response = Sisly::message(
                sessionId: $request->input('session_id'),
                message: $request->input('message')
            );

            return response()->json([
                'message' => $response->responseText,
                'arabic' => $response->arabicMirror,
                'state' => $response->state->value,
                'turn' => $response->turnCount,
                'is_crisis' => $response->crisis->detected,
                'crisis_info' => $response->crisis->detected ? [
                    'severity' => $response->crisis->severity->value,
                    'category' => $response->crisis->category->value,
                ] : null,
            ]);
        } catch (SessionNotFoundException $e) {
            return response()->json([
                'error' => 'Session not found',
                'code' => 'SESSION_NOT_FOUND',
            ], 404);
        } catch (InvalidMessageException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'INVALID_MESSAGE',
            ], 422);
        }
    }

    /**
     * Get current session state.
     */
    public function state(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
        ]);

        try {
            $state = Sisly::getState($request->input('session_id'));

            return response()->json([
                'state' => $state['state'],
                'turn_count' => $state['turn_count'],
                'is_active' => $state['is_active'],
                'coach_id' => $state['coach_id'],
            ]);
        } catch (SessionNotFoundException $e) {
            return response()->json([
                'error' => 'Session not found',
                'code' => 'SESSION_NOT_FOUND',
            ], 404);
        }
    }

    /**
     * End a session.
     */
    public function end(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
        ]);

        Sisly::endSession($request->input('session_id'));

        return response()->json([
            'success' => true,
            'message' => 'Session ended',
        ]);
    }
}
```

### Routes

```php
// routes/api.php
use App\Http\Controllers\CoachingController;

Route::prefix('v1/coaching')->middleware(['auth:sanctum'])->group(function () {
    Route::post('/start', [CoachingController::class, 'start']);
    Route::post('/message', [CoachingController::class, 'message']);
    Route::get('/state', [CoachingController::class, 'state']);
    Route::post('/end', [CoachingController::class, 'end']);
});
```

### API Response Format

```json
{
    "session_id": "user-123-1706889600",
    "message": "I hear that you're feeling anxious about your presentation tomorrow. That's completely understandable - presentations can bring up a lot of emotions. Can you tell me more about what specifically is making you feel anxious?",
    "arabic": "أسمع إنك حاسس بالقلق من العرض التقديمي بكرة. هذا شي طبيعي تماماً - العروض ممكن تثير مشاعر كثيرة. تقدر تخبرني أكثر شو بالضبط اللي يخليك تحس بالقلق؟",
    "coach": "meetly",
    "state": "exploration",
    "turn": 2,
    "is_crisis": false
}
```

---

## WebSocket Integration

### Laravel Echo + Pusher

```php
// app/Events/CoachingResponse.php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Sisly\DTOs\SislyResponse;

class CoachingResponse implements ShouldBroadcast
{
    public function __construct(
        public string $sessionId,
        public SislyResponse $response
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('coaching.' . $this->sessionId);
    }

    public function broadcastWith(): array
    {
        return [
            'message' => $this->response->responseText,
            'arabic' => $this->response->arabicMirror,
            'state' => $this->response->state->value,
            'is_crisis' => $this->response->crisis->detected,
        ];
    }
}
```

### Async Processing with Queues

```php
// app/Jobs/ProcessCoachingMessage.php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Sisly\Facades\Sisly;
use App\Events\CoachingResponse;

class ProcessCoachingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public string $sessionId,
        public string $message
    ) {}

    public function handle(): void
    {
        $response = Sisly::message($this->sessionId, $this->message);

        broadcast(new CoachingResponse($this->sessionId, $response));
    }
}
```

---

## Event Handling

### Available Events

| Event | Triggered When |
|-------|----------------|
| `SessionStarted` | New session begins |
| `MessageReceived` | User sends message |
| `ResponseGenerated` | Coach response ready |
| `StateTransitioned` | FSM state changes |
| `SessionEnded` | Session closes |
| `CrisisDetected` | Crisis keywords found |
| `LLMFailoverOccurred` | Primary LLM failed |

### Event Listeners

```php
// app/Providers/EventServiceProvider.php
use Sisly\Events\CrisisDetected;
use Sisly\Events\LLMFailoverOccurred;
use Sisly\Events\SessionStarted;

protected $listen = [
    CrisisDetected::class => [
        \App\Listeners\NotifyCrisisTeam::class,
        \App\Listeners\LogCrisisEvent::class,
    ],
    LLMFailoverOccurred::class => [
        \App\Listeners\AlertOpsTeam::class,
    ],
    SessionStarted::class => [
        \App\Listeners\TrackSessionStart::class,
    ],
];
```

### Crisis Handler Example

```php
// app/Listeners/NotifyCrisisTeam.php
namespace App\Listeners;

use Sisly\Events\CrisisDetected;
use Illuminate\Support\Facades\Notification;
use App\Notifications\CrisisAlert;

class NotifyCrisisTeam
{
    public function handle(CrisisDetected $event): void
    {
        $admins = User::role('crisis-responder')->get();

        Notification::send($admins, new CrisisAlert(
            sessionId: $event->sessionId,
            category: $event->category->value,
            severity: $event->severity->value,
            keywords: $event->keywords,
        ));

        // Log for compliance
        activity()
            ->causedByAnonymous()
            ->withProperties([
                'session_id' => $event->sessionId,
                'category' => $event->category->value,
                'severity' => $event->severity->value,
            ])
            ->log('Crisis detected in coaching session');
    }
}
```

---

## Error Handling

### Exception Types

```php
use Sisly\Exceptions\SislyException;
use Sisly\Exceptions\SessionNotFoundException;
use Sisly\Exceptions\InvalidMessageException;
use Sisly\Exceptions\CoachNotFoundException;
use Sisly\Exceptions\LLMException;
use Sisly\Exceptions\ConfigurationException;

try {
    $response = Sisly::message($sessionId, $message);
} catch (SessionNotFoundException $e) {
    // Session doesn't exist
} catch (InvalidMessageException $e) {
    // Message is empty or too long
    $reason = $e->getReason(); // 'empty', 'too_long', 'invalid_format'
} catch (LLMException $e) {
    // All LLM providers failed
    $reason = $e->getReason();
    $provider = $e->getProvider();
} catch (SislyException $e) {
    // Generic Sisly error
}
```

### Global Exception Handler

```php
// app/Exceptions/Handler.php
use Sisly\Exceptions\SislyException;
use Sisly\Exceptions\SessionNotFoundException;

public function register(): void
{
    $this->renderable(function (SessionNotFoundException $e, $request) {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'Session not found',
                'code' => 'SESSION_NOT_FOUND',
            ], 404);
        }
    });

    $this->renderable(function (SislyException $e, $request) {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => 'SISLY_ERROR',
            ], 500);
        }
    });
}
```

---

## Testing

### Feature Test Example

```php
// tests/Feature/CoachingTest.php
namespace Tests\Feature;

use Tests\TestCase;
use Sisly\Facades\Sisly;
use Sisly\LLM\MockProvider;

class CoachingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Use mock provider for tests
        config(['sisly.llm.driver' => 'mock']);
    }

    public function test_can_start_coaching_session(): void
    {
        $response = $this->postJson('/api/v1/coaching/start', [
            'message' => 'I feel anxious about my job interview',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'session_id',
                'message',
                'arabic',
                'coach',
                'state',
            ]);
    }

    public function test_crisis_detection_works(): void
    {
        $response = $this->postJson('/api/v1/coaching/start', [
            'message' => 'I want to end my life',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'is_crisis' => true,
                'state' => 'crisis_intervention',
            ])
            ->assertJsonStructure([
                'crisis_info' => ['severity', 'category'],
            ]);
    }
}
```

### Mocking LLM Responses

```php
use Sisly\LLM\MockProvider;
use Sisly\Contracts\LLMProviderInterface;

public function test_specific_response(): void
{
    $mock = new MockProvider();
    $mock->addResponse('anxious', 'I understand you feel anxious...');

    $this->app->instance(LLMProviderInterface::class, $mock);

    $response = Sisly::startSession('I feel anxious');

    $this->assertStringContainsString('anxious', $response->responseText);
}
```

---

## Production Considerations

### Caching

```php
// Warm up coach prompts on deploy
php artisan sisly:cache-prompts
```

### Monitoring

```php
// Log LLM latency
Event::listen(ResponseGenerated::class, function ($event) {
    Metrics::histogram('sisly.response_time', $event->responseTimeMs, [
        'coach' => $event->coachId->value,
        'state' => $event->state->value,
    ]);
});

// Alert on high failover rate
Event::listen(LLMFailoverOccurred::class, function ($event) {
    Metrics::increment('sisly.failover', 1, [
        'from' => $event->previousProvider,
        'to' => $event->newProvider,
    ]);
});
```

### Rate Limiting

```php
// routes/api.php
Route::middleware(['throttle:coaching'])->group(function () {
    Route::post('/coaching/message', [CoachingController::class, 'message']);
});

// app/Providers/RouteServiceProvider.php
RateLimiter::for('coaching', function (Request $request) {
    return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
});
```

### Health Check

```php
// routes/api.php
Route::get('/health/sisly', function () {
    $llm = app(LLMProviderInterface::class);

    return response()->json([
        'status' => $llm->isAvailable() ? 'healthy' : 'degraded',
        'provider' => $llm->getName(),
    ]);
});
```

---

## Next Steps

- [Configuration Guide](CONFIGURATION.md) — All configuration options
- [Extending Guide](EXTENDING.md) — Create custom coaches
