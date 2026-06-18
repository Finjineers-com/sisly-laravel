<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | LLM Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Two-tier model strategy (as per the functional spec):
    |   - safety_model: cheap/fast model for the parallel safety classifier
    |   - coach_model:  higher-quality model for coach responses
    |
    | Supported providers: "openai", "gemini", "anthropic", "mock"
    |
    */
    'llm' => [
        // Primary provider: "openai", "gemini", "anthropic", or "mock" for testing
        'driver' => env('SISLY_LLM_DRIVER', 'anthropic'),

        // Enable failover to backup provider
        'failover_enabled' => env('SISLY_LLM_FAILOVER', true),

        // Number of failures before circuit breaker trips
        'failure_threshold' => env('SISLY_LLM_FAILURE_THRESHOLD', 5),

        // OpenAI configuration
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
            'safety_model' => env('OPENAI_SAFETY_MODEL', 'gpt-4o-mini'),
            'timeout' => env('OPENAI_TIMEOUT', 30),
            'max_retries' => env('OPENAI_MAX_RETRIES', 3),
            'retry_delay' => env('OPENAI_RETRY_DELAY', 1000), // milliseconds
        ],

        // Google Gemini configuration
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-1.5-pro'),
            'safety_model' => env('GEMINI_SAFETY_MODEL', 'gemini-1.5-flash'),
            'timeout' => env('GEMINI_TIMEOUT', 30),
            'max_retries' => env('GEMINI_MAX_RETRIES', 3),
            'retry_delay' => env('GEMINI_RETRY_DELAY', 1000), // milliseconds
        ],

        // Anthropic Claude configuration (recommended for this use case)
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
            'safety_model' => env('ANTHROPIC_SAFETY_MODEL', 'claude-haiku-4-5'),
            'timeout' => env('ANTHROPIC_TIMEOUT', 30),
            'max_retries' => env('ANTHROPIC_MAX_RETRIES', 3),
            'retry_delay' => env('ANTHROPIC_RETRY_DELAY', 1000), // milliseconds
        ],

        // Temperature settings per session state
        'temperature' => [
            'intake'              => 0.7,
            'exploration'         => 0.7,
            'deepening'           => 0.6,
            'problem_solving'     => 0.5,
            'closing'             => 0.6,
            'crisis_intervention' => 0.0, // Deterministic for safety
            'safety_classifier'   => 0.0, // Always deterministic
        ],

        // Token limits (spec: coach 1-3 sentences ≈ 150 tokens; safety 200 max)
        'max_tokens' => [
            'default'            => 150,
            'technique'          => 300,
            'crisis'             => 200,
            'safety_classifier'  => 200,
            'dispatcher'         => 150,
        ],

        // Prompt caching: cache the system prefix per coach (major cost lever)
        'prompt_caching' => [
            'enabled' => env('SISLY_PROMPT_CACHE', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Parallel Safety Classifier
    |--------------------------------------------------------------------------
    |
    | The safety call runs IN PARALLEL with the coach call (Promise.all pattern).
    | It uses a separate, cheaper model and its verdict always overrides the coach.
    | "fail closed": if the safety response cannot be parsed, treat as "checking".
    |
    */
    'safety_classifier' => [
        // Run safety in parallel with coach call (non-negotiable per spec)
        'parallel_enabled' => true,

        // Verdicts: "ok" | "checking" | "flagged"
        // "flagged" => discard coach output, show crisis response, set ended=true
        // "checking" => keep coach reply but surface amber badge
        // "ok" => normal flow
        'fail_closed_verdict' => 'checking', // verdict when parsing fails

        // Use the cheaper/faster model tier for safety
        'use_safety_model' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | FSM (Finite State Machine) Configuration
    |--------------------------------------------------------------------------
    |
    | The Sisly method follows: primed opening (no model call) → understand and
    | validate → detect current and target mood → handoff with typed content
    | prescription → keep talking (non-terminal; only a crisis ends the chat).
    |
    */
    'fsm' => [
        // Hard cap on total turns (safety net for runaway sessions)
        'max_total_turns' => 40,

        // Wall-clock cap in seconds. Opt-in: null disables.
        // 600 = 10-minute sessions as recommended in CHANGELOG
        'max_session_seconds' => null,

        // When true, transitioning into CLOSING immediately ends the session.
        // Per spec: "the chat only ends on a crisis verdict; the content
        // recommendation never ends it." Set false for chat-app UX.
        'end_on_terminal_state' => false,

        // Fraction of max_session_seconds at which FSM force-transitions to
        // CLOSING so the bot can wrap gracefully.
        'nearing_end_threshold' => 0.85,

        // Per-state turn limits. Spec: "Understand and validate for 3-4 short
        // turns", then handoff. These are per-state caps, not total.
        'turn_limits' => [
            'intake'          => 1,
            'risk_triage'     => 0, // Auto pass-through
            'exploration'     => 4, // Spec: 3-4 turns of understand+validate
            'deepening'       => 2,
            'problem_solving' => 5, // After handoff, keep talking
            'closing'         => 2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Storage Configuration
    |--------------------------------------------------------------------------
    */
    'session' => [
        'driver' => env('SISLY_SESSION_DRIVER', 'cache'),
        'prefix' => 'sisly:session:',
        'ttl' => 1800, // 30 minutes idle TTL in seconds

        // Rolling history sent to the model.
        // Spec: "send only the rolling state plus the last exchange to the
        // model, never the full transcript."
        'max_history_turns' => 6, // last_2_messages rolling window per spec
    ],

    /*
    |--------------------------------------------------------------------------
    | Coach State (CoachState DTO)
    |--------------------------------------------------------------------------
    |
    | Persistent per-session state aligned to spec:
    | { coach_id, locale, turn, situation_summary, current_mood, target_mood,
    |   last_2_messages }
    |
    | Cross-session memory defaults to OFF (start fresh per session).
    |
    */
    'coach_state' => [
        // Persist situation_summary across sessions? Default OFF per spec.
        'cross_session_memory' => env('SISLY_CROSS_SESSION_MEMORY', false),

        // Valid mood values from spec
        'moods' => ['Excited', 'Happy', 'Calm', 'Anxious', 'Sad'],

        // Valid content types for prescription handoff
        'content_types' => ['Meditation', 'DoWithMe', 'Affirmation', 'Sound', 'Podcast'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Coach Configuration
    |--------------------------------------------------------------------------
    |
    | Five coaches per spec v2: Meetly, Presso, Loopy, Boostly, Vento.
    | Safeo is available as a sixth (was in v1; still present in prompts).
    |
    */
    'coaches' => [
        'default' => 'meetly',
        'enabled' => ['meetly', 'vento', 'loopy', 'presso', 'boostly', 'safeo'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety Configuration
    |--------------------------------------------------------------------------
    |
    | Two-layer safety:
    | 1. Deterministic keyword lexicon (runs BEFORE any LLM call) — crisis_detection
    | 2. LLM safety classifier (runs IN PARALLEL with coach call) — safety_classifier above
    |
    | WARNING: Never disable crisis_detection in production!
    |
    */
    'safety' => [
        'crisis_detection' => true,
        'crisis_lexicon_path' => null, // Use package default if null
        'post_response_validation' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Language & Locale Configuration
    |--------------------------------------------------------------------------
    |
    | Locale is a per-session attribute. Supports EN and AR.
    | Arabic uses Gulf dialect (Khaleeji) as specified.
    | Per spec: "Never mix languages within one reply unless user did first."
    |
    */
    'language' => [
        'supported' => ['en', 'ar'],
        'default' => 'en',

        // Auto-detect language from first user message when not specified
        'auto_detect' => env('SISLY_LANGUAGE_AUTO_DETECT', true),

        // Arabic dialect: 'gulf' (Khaleeji) or 'msa' (Modern Standard Arabic)
        'arabic_dialect' => 'gulf',

        // Emit one short Arabic empathy line in English sessions when true.
        // Spec: "Reply in the same language the person is using."
        // Deprecated in favour of strict single-language mode (false recommended).
        'arabic_mirror_enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prescription (Content Handoff) Configuration
    |--------------------------------------------------------------------------
    |
    | When the coach reaches handoff, it emits a ```sisly block.
    | The package parses it and (optionally) resolves it to a real asset.
    |
    */
    'prescription' => [
        // Resolve prescription to a real content asset?
        // Set true to resolve a real content asset from the library.
        'resolve_assets' => env('SISLY_RESOLVE_ASSETS', true),

        // Asset resolver class (must implement AssetResolverInterface)
        'asset_resolver' => \Sisly\Resolvers\CoachMappedAssetResolver::class,

        // Never recommend an English asset in an Arabic session (and vice versa)
        'locale_strict' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Library Configuration
    |--------------------------------------------------------------------------
    |
    | Used by the default resolver to fetch real media recommendations.
    |
    */
    'content_library' => [
        'endpoint' => env('SISLY_CONTENT_LIBRARY_ENDPOINT', 'https://api.sisly.ai/api/v1/insights/by-type'),
        'timeout' => env('SISLY_CONTENT_LIBRARY_TIMEOUT', 15),
        'default_coach_id' => env('SISLY_CONTENT_LIBRARY_DEFAULT_COACH', 'meetly'),
        'current_coach_id' => env('SISLY_CONTENT_LIBRARY_CURRENT_COACH_ID', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Crisis Resources Configuration
    |--------------------------------------------------------------------------
    */
    'crisis_resources' => [
        'use_package_defaults' => true,
        'custom_path' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dispatcher Configuration
    |--------------------------------------------------------------------------
    */
    'dispatcher' => [
        'enabled' => true,
        'confidence_threshold' => 0.7,

        // Use the cheaper safety_model for dispatcher (it's classification only)
        'use_safety_model' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry / Logging
    |--------------------------------------------------------------------------
    |
    | Per spec: log coach_id, locale, turn, verdict, had_prescription,
    | content_type (if any), latency. NEVER log raw message content.
    |
    */
    'telemetry' => [
        'enabled' => env('SISLY_TELEMETRY', true),

        // Log per-turn metrics (coach_id, locale, turn, verdict, latency...)
        'log_turn_metrics' => true,

        // NEVER log raw user messages (privacy, per spec)
        'log_message_content' => false,
    ],
];
