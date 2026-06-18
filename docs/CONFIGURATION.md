# Configuration Reference

Publish the config file with:

```bash
php artisan vendor:publish --tag=sisly-config
```

All options are in `config/sisly.php`. See the inline comments for details.

## Two-Tier Model Architecture

Sisly uses two model tiers:

- **Coach model** (`config.llm.{driver}.model`) — full quality, used for coaching responses
- **Safety model** (`config.llm.{driver}.safety_model`) — cheap/fast, used for:
  - Safety classifier (parallel call)
  - Dispatcher (coach routing)

Recommended for Anthropic:
```env
ANTHROPIC_MODEL=claude-sonnet-4-5
ANTHROPIC_SAFETY_MODEL=claude-haiku-4-5
```

## Key Config Sections

### `safety_classifier`

```php
'safety_classifier' => [
    'parallel_enabled' => true,          // Run alongside coach call
    'fail_closed_verdict' => 'checking', // On parse failure, never crash open
    'use_safety_model' => true,          // Use cheap model tier
],
```

### `prescription`

```php
'prescription' => [
    'resolve_assets' => false,      // Wire your AssetResolver to enable
    'asset_resolver' => null,       // Your class implementing AssetResolverInterface
    'locale_strict' => true,        // Never mix locales on assets
],
```

### `fsm`

```php
'fsm' => [
    'end_on_terminal_state' => false,  // Chat stays open after handoff (spec default)
    'max_total_turns' => 40,
    'max_session_seconds' => null,     // Optional wall-clock cap (e.g. 600 for 10 min)
],
```

### `language`

```php
'language' => [
    'auto_detect' => true,       // Detect EN/AR from first user message
    'arabic_dialect' => 'gulf',  // 'gulf' (Khaleeji) or 'msa'
],
```

### `telemetry`

```php
'telemetry' => [
    'enabled' => true,
    'log_turn_metrics' => true,
    'log_message_content' => false,  // NEVER log raw messages
],
```

For full details, see the annotated `config/sisly.php`.
