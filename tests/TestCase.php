<?php

declare(strict_types = 1);

namespace Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use SineMacula\Log\CloudWatch\LogCloudWatchServiceProvider;

/**
 * Base test case for the laravel-log-cloudwatch package.
 *
 * Registers the package service provider against a Testbench application. The
 * CloudWatch driver touches neither the database nor the cache, so no further
 * environment setup is required.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
abstract class TestCase extends OrchestraTestCase
{
    /**
     * Get the package providers.
     *
     * @param  mixed  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            LogCloudWatchServiceProvider::class,
        ];
    }
}
