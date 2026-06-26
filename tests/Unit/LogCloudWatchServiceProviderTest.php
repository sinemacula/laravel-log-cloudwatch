<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use PhpNexus\Cwh\Handler\CloudWatch as CloudWatchHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Log\CloudWatch\LogCloudWatchServiceProvider;
use Tests\TestCase;

/**
 * Tests for the LogCloudWatchServiceProvider.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(LogCloudWatchServiceProvider::class)]
final class LogCloudWatchServiceProviderTest extends TestCase
{
    /**
     * Test that the provider registers a `cloudwatch` driver that resolves to a
     * CloudWatch-backed Monolog logger.
     *
     * @return void
     */
    public function testCloudwatchChannelResolvesToCloudWatchBackedLogger(): void
    {
        Config::set('logging.channels.cloudwatch', [
            'driver'     => 'cloudwatch',
            'aws'        => ['region' => 'us-east-1'],
            'log_group'  => 'test-log-group',
            'log_stream' => 'test-log-stream',
        ]);

        $channel = Log::channel('cloudwatch');

        self::assertInstanceOf(IlluminateLogger::class, $channel);

        $logger = $channel->getLogger();

        self::assertInstanceOf(Logger::class, $logger);
        self::assertInstanceOf(CloudWatchHandler::class, $logger->getHandlers()[0]);
    }
}
