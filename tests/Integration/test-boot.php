<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Orchestra\Testbench\Foundation\Application;
use Sisly\Facades\Sisly;

echo "Bootstrapping Orchestra application...\n";

try {
    $app = Application::create(
        dirname(__DIR__, 2),
        function ($app) {
            $app->register(\Sisly\SislyServiceProvider::class);
        }
    );

    \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    
    // Set config values from package config
    $config = require dirname(__DIR__, 2) . '/config/sisly.php';
    config(['sisly' => $config]);

    // Load .env values
    $envFile = dirname(__DIR__, 2) . '/.env.testing';
    if (!file_exists($envFile)) {
        $envFile = dirname(__DIR__, 2) . '/.env';
    }

    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                putenv("{$k}={$v}");
                $_ENV[$k] = $v;
                
                // Map to config
                if ($k === 'SISLY_LLM_DRIVER') {
                    config(['sisly.llm.driver' => $v]);
                } elseif ($k === 'ANTHROPIC_API_KEY') {
                    config(['sisly.llm.anthropic.api_key' => $v]);
                }
            }
        }
    }

    echo "Orchestra application bootstrapped successfully!\n";
    echo "Active Driver: " . config('sisly.llm.driver') . "\n";
    echo "Anthropic Key: " . (config('sisly.llm.anthropic.api_key') ? 'Configured' : 'Missing') . "\n";
} catch (\Throwable $e) {
    echo "Failed to bootstrap: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
