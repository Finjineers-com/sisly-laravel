<?php

declare(strict_types=1);

namespace Sisly\Exceptions;

/**
 * Exception thrown when configuration is invalid or missing.
 */
class ConfigurationException extends SislyException
{
    private string $configKey;

    public function __construct(string $configKey, string $message = '')
    {
        $this->configKey = $configKey;

        if (empty($message)) {
            $message = "Invalid or missing configuration: {$configKey}";
        }

        parent::__construct($message);
    }

    public function getConfigKey(): string
    {
        return $this->configKey;
    }

    public static function missing(string $key): self
    {
        return new self($key, "Missing required configuration: {$key}");
    }

    public static function invalid(string $key, string $reason): self
    {
        return new self($key, "Invalid configuration '{$key}': {$reason}");
    }
}
