<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | LLM Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the primary and fallback LLM providers. The system will
    | automatically fail over to the fallback provider if the primary fails.
    |
    | Supported providers: "openai", "gemini", "anthropic", "mock"
    |
    */
    'llm' => [
        // Provider to use: "openai", "gemini", "anthropic", or "mock" for testing
        'driver' => env('SISLY_LLM_DRIVER', 'openai'),

        // Enable failover to backup provider
        'failover_enabled' => env('SISLY_LLM_FAILOVER', true),

        // Number of failures before circuit breaker trips
        'failure_threshold' => env('SISLY_LLM_FAILURE_THRESHOLD', 5),

        // OpenAI configuration
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4-turbo'),
            'timeout' => env('OPENAI_TIMEOUT', 30),
            'max_retries' => env('OPENAI_MAX_RETRIES', 3),
            'retry_delay' => env('OPENAI_RETRY_DELAY', 1000), // milliseconds
        ],

        // Google Gemini configuration
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-pro'),
            'timeout' => env('GEMINI_TIMEOUT', 30),
            'max_retries' => env('GEMINI_MAX_RETRIES', 3),
            'retry_delay' => env('GEMINI_RETRY_DELAY', 1000), // milliseconds
        ],

        // Anthropic Claude configuration
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
            'timeout' => env('ANTHROPIC_TIMEOUT', 30),
            'max_retries' => env('ANTHROPIC_MAX_RETRIES', 3),
            'retry_delay' => env('ANTHROPIC_RETRY_DELAY', 1000), // milliseconds
        ],

        // Temperature settings per session state
        'temperature' => [
            'intake' => 0.7,
            'exploration' => 0.7,
            'deepening' => 0.6,
            'problem_solving' => 0.5,
            'closing' => 0.6,
            'crisis_intervention' => 0.0, // Deterministic for safety
        ],

        // Token limits
        'max_tokens' => [
            'default' => 150,
            'technique' => 300, // Technique instructions can be longer
            'crisis' => 200,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | FSM (Finite State Machine) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the state machine behavior including maximum turns and
    | turn limits per state.
    |
    */
    'fsm' => [
        // Hard cap on total turns (= 2 internal turns per user-perceived cycle).
        // Acts as a runaway-LLM safety net. Set to 60 to support 15-20 user
        // message conversations comfortably (each exchange = 2 internal turns).
        'max_total_turns' => 60,

        // Wall-clock cap in seconds. Opt-in: null disables (matches v1.2.0
        // behaviour). Set e.g. 600 for 10-minute sessions.
        'max_session_seconds' => null,

        // When true, transitioning into CLOSING immediately ends the session
        // (the v1.2.0 behaviour — preserved as default for back-compat).
        // Set to false for chat-app UX where CLOSING is a livable wrap-up
        // state rather than a cliff.
        'end_on_terminal_state' => true,

        // Fraction of max_session_seconds at which the FSM force-transitions
        // to CLOSING so the bot can wrap gracefully. Only fires when
        // max_session_seconds is set. 0.85 ≈ ~1.5 min closing window in a
        // 10-min budget.
        'nearing_end_threshold' => 0.85,

        // Per-state turn limits (in user-perceived cycles, NOT internal turns).
        // Extended to give each FSM phase enough room for a natural 15-20
        // message conversation. INTAKE is always 1 (first message only).
        // PROBLEM_SOLVING is generous because technique delivery + follow-up
        // questions can span many turns.
        'turn_limits' => [
            'intake'          => 1,   // First message only — transitions immediately
            'risk_triage'     => 0,   // Auto pass-through
            'exploration'     => 4,   // Up to 4 exchanges to understand the issue
            'deepening'       => 3,   // Summary + insight + time choice
            'problem_solving' => 8,   // Technique delivery + check-ins + variants
            'closing'         => 3,   // Check-in + anchor + end signal
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how sessions are stored. The default uses Laravel's cache
    | system, but Redis can be used for production workloads.
    |
    | Supported drivers: "cache", "redis"
    |
    */
    'session' => [
        'driver' => env('SISLY_SESSION_DRIVER', 'cache'),
        'prefix' => 'sisly:session:',
        'ttl' => 1800, // 30 minutes idle TTL in seconds

        // FIFO cap on conversation history kept on the Session object —
        // i.e. how many recent turns the LLM sees in its context. Each
        // user-perceived cycle = 2 history entries (1 user + 1 assistant).
        // Set to 60 so a 20-message conversation is always fully in context.
        'max_history_turns' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Coach Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which coaches are enabled and the default coach to use
    | when no specific coach is requested.
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
    | Configure safety features including crisis detection and post-response
    | validation. These settings are critical for user safety.
    |
    | WARNING: Do not disable crisis_detection in production!
    |
    */
    'safety' => [
        'crisis_detection' => true,
        'crisis_lexicon_path' => null, // Use package default if null
        'post_response_validation' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Arabic/Bilingual Configuration
    |--------------------------------------------------------------------------
    |
    | Configure Arabic language support for GCC users. When enabled, responses
    | can include an Arabic "mirror" translation.
    |
    */
    'arabic' => [
        'enabled' => true,
        'dialect' => 'gulf', // "gulf" (Khaleeji) or "msa" (Modern Standard Arabic)
        'mirror_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Crisis Resources Configuration
    |--------------------------------------------------------------------------
    |
    | Configure crisis resources (hotlines, emergency contacts) for different
    | countries. The package includes GCC defaults.
    |
    */
    'crisis_resources' => [
        'use_package_defaults' => true, // Use built-in GCC resources
        'custom_path' => null,          // Path to custom crisis resources JSON
    ],

    /*
    |--------------------------------------------------------------------------
    | Prescription Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the media content prescription and caching system.
    |
    */
    'prescription' => [
        'enabled' => env('SISLY_PRESCRIPTION_ENABLED', true),
        'api_url' => env('SISLY_PRESCRIPTION_API_URL', 'https://api.sisly.ai/api/v1/insights/by-type'),
        'cache_ttl' => env('SISLY_PRESCRIPTION_CACHE_TTL', 1800), // 30 minutes
        'max_tokens_handoff' => env('SISLY_PRESCRIPTION_MAX_TOKENS_HANDOFF', 400),
    ],
];
