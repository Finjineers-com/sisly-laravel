# Configuration Guide

This document provides detailed information about all configuration options available in Sisly.

## Table of Contents

- [Publishing Configuration](#publishing-configuration)
- [LLM Configuration](#llm-configuration)
- [Session Configuration](#session-configuration)
- [Coach Configuration](#coach-configuration)
- [Safety Configuration](#safety-configuration)
- [Arabic Configuration](#arabic-configuration)
- [FSM Configuration](#fsm-configuration)
- [Environment Variables](#environment-variables)

---

## Publishing Configuration

Publish the configuration file to your Laravel application:

```bash
php artisan vendor:publish --tag=sisly-config
```

This creates `config/sisly.php` in your application.

---

## LLM Configuration

### Provider Selection

```php
'llm' => [
    // Primary provider: 'openai', 'gemini', or 'mock' (for testing)
    'driver' => env('SISLY_LLM_DRIVER', 'openai'),

    // Enable automatic failover to backup provider
    'failover_enabled' => env('SISLY_LLM_FAILOVER', true),

    // Number of consecutive failures before circuit breaker trips
    'failure_threshold' => env('SISLY_LLM_FAILURE_THRESHOLD', 5),
],
```

### OpenAI Configuration

```php
'openai' => [
    // Your OpenAI API key
    'api_key' => env('OPENAI_API_KEY'),

    // Model to use (gpt-4-turbo recommended for best results)
    'model' => env('OPENAI_MODEL', 'gpt-4-turbo'),

    // Request timeout in seconds
    'timeout' => env('OPENAI_TIMEOUT', 30),

    // Maximum retry attempts for failed requests
    'max_retries' => env('OPENAI_MAX_RETRIES', 3),

    // Delay between retries in milliseconds
    'retry_delay' => env('OPENAI_RETRY_DELAY', 1000),
],
```

### Gemini Configuration

```php
'gemini' => [
    // Your Google Gemini API key
    'api_key' => env('GEMINI_API_KEY'),

    // Model to use
    'model' => env('GEMINI_MODEL', 'gemini-pro'),

    // Request timeout in seconds
    'timeout' => env('GEMINI_TIMEOUT', 30),

    // Maximum retry attempts
    'max_retries' => env('GEMINI_MAX_RETRIES', 3),

    // Delay between retries in milliseconds
    'retry_delay' => env('GEMINI_RETRY_DELAY', 1000),
],
```

### Temperature Settings

Temperature controls response creativity. Lower values = more deterministic.

```php
'temperature' => [
    'intake' => 0.7,           // Initial greeting, warm and engaging
    'exploration' => 0.7,      // Understanding the situation
    'deepening' => 0.6,        // More focused emotional exploration
    'problem_solving' => 0.5,  // Practical, consistent techniques
    'closing' => 0.6,          // Warm wrap-up
    'crisis_intervention' => 0.0,  // CRITICAL: Must be deterministic
],
```

### Token Limits

```php
'max_tokens' => [
    'default' => 150,     // Standard responses
    'technique' => 300,   // Technique instructions can be longer
    'crisis' => 200,      // Crisis responses
],
```

---

## Session Configuration

### Storage Driver

```php
'session' => [
    // Driver: 'cache' (uses Laravel's cache) or 'redis' (direct Redis)
    'driver' => env('SISLY_SESSION_DRIVER', 'cache'),

    // Prefix for session keys
    'prefix' => 'sisly:session:',

    // Session TTL in seconds (default: 30 minutes)
    'ttl' => 1800,
],
```

### Using Redis Driver

For high-traffic applications, use the Redis driver:

```env
SISLY_SESSION_DRIVER=redis
```

Ensure Redis is configured in your Laravel application:

```php
// config/database.php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
    ],
],
```

---

## Coach Configuration

### Enabling Coaches

```php
'coaches' => [
    // Default coach when dispatcher cannot determine
    'default' => 'meetly',

    // List of enabled coaches
    'enabled' => ['meetly', 'vento', 'loopy', 'presso', 'boostly'],
],
```

### Disabling Coaches

To disable specific coaches:

```php
'enabled' => ['meetly', 'presso'],  // Only MEETLY and PRESSO available
```

---

## Safety Configuration

**Warning**: Safety features should NEVER be disabled in production.

```php
'safety' => [
    // Enable crisis detection (NEVER disable in production)
    'crisis_detection' => true,

    // Path to custom crisis lexicon (null = use package default)
    'crisis_lexicon_path' => null,

    // Enable post-response validation
    'post_response_validation' => true,
],
```

### Custom Crisis Lexicon

To add custom crisis keywords:

```php
'crisis_lexicon_path' => resource_path('sisly/custom_crisis_lexicon.json'),
```

Format:

```json
{
  "categories": {
    "custom_category": {
      "severity": "critical",
      "patterns": {
        "en": [{"text": "custom keyword", "type": "exact"}],
        "ar": [{"text": "كلمة مخصصة", "type": "exact"}]
      }
    }
  }
}
```

### Crisis Resources

```php
'crisis_resources' => [
    // Use built-in GCC resources
    'use_package_defaults' => true,

    // Path to custom resources (only used if use_package_defaults is false)
    'custom_path' => null,
],
```

---

## Arabic Configuration

```php
'arabic' => [
    // Enable Arabic support
    'enabled' => true,

    // Dialect for Arabic mirror: 'gulf' (Khaleeji) or 'msa' (Modern Standard)
    'dialect' => 'gulf',

    // Generate Arabic mirror for each response
    'mirror_enabled' => true,
],
```

### Dialect Options

| Dialect | Description | Best For |
|---------|-------------|----------|
| `gulf` | Gulf Arabic (Khaleeji) | UAE, Saudi Arabia, Kuwait, Bahrain, Qatar, Oman |
| `msa` | Modern Standard Arabic | Formal contexts, pan-Arab audience |

---

## FSM Configuration

Control the conversation flow through the Finite State Machine:

```php
'fsm' => [
    // Maximum turns before session auto-closes
    'max_total_turns' => 20,

    // Turns allowed in each state before auto-advancing
    'turn_limits' => [
        'intake' => 1,
        'risk_triage' => 0,      // Auto-advances immediately
        'exploration' => 2,
        'deepening' => 1,
        'problem_solving' => 3,
        'closing' => 1,
    ],
],
```

---

## Environment Variables

Complete list of environment variables:

```env
# LLM Provider
SISLY_LLM_DRIVER=openai
SISLY_LLM_FAILOVER=true
SISLY_LLM_FAILURE_THRESHOLD=5

# OpenAI
OPENAI_API_KEY=sk-your-key
OPENAI_MODEL=gpt-4-turbo
OPENAI_TIMEOUT=30
OPENAI_MAX_RETRIES=3
OPENAI_RETRY_DELAY=1000

# Gemini
GEMINI_API_KEY=your-key
GEMINI_MODEL=gemini-pro
GEMINI_TIMEOUT=30
GEMINI_MAX_RETRIES=3
GEMINI_RETRY_DELAY=1000

# Session
SISLY_SESSION_DRIVER=cache
```

---

## Runtime Configuration

Some settings can be changed at runtime:

```php
use Sisly\Facades\Sisly;

// Change default coach
config(['sisly.coaches.default' => 'presso']);

// Disable Arabic mirror for specific session
config(['sisly.arabic.mirror_enabled' => false]);
```

---

## Validation

Sisly validates configuration on boot. Invalid configuration will throw `ConfigurationException`:

```php
use Sisly\Exceptions\ConfigurationException;

try {
    $response = Sisly::startSession('Hello');
} catch (ConfigurationException $e) {
    echo "Configuration error: " . $e->getConfigKey();
}
```

---

## Next Steps

- [Integration Guide](INTEGRATION.md) — How to integrate Sisly in your application
- [Extending Guide](EXTENDING.md) — How to create custom coaches
