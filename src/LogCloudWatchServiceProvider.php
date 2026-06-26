<?php

declare(strict_types = 1);

namespace SineMacula\Log\CloudWatch;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger;

/**
 * Service provider for the CloudWatch log driver.
 *
 * Registers the `cloudwatch` custom Monolog driver with Laravel's log manager.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class LogCloudWatchServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the CloudWatch log driver.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->app->make(LogManager::class)->extend('cloudwatch', fn (Application $app, array $config): Logger => (new CloudWatchLoggerFactory($app))->__invoke($config));
    }
}
