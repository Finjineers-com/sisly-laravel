<?php

declare(strict_types=1);

namespace Sisly\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Sisly\SislyServiceProvider;

/**
 * Base test case for Sisly package tests.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SislyServiceProvider::class,
        ];
    }

    /**
     * Get package aliases.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Sisly' => \Sisly\Facades\Sisly::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('sisly.session.driver', 'cache');
    }
}
