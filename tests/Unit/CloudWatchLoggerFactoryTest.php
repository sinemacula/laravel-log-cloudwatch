<?php

declare(strict_types = 1);

namespace Tests\Unit;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Processor\MemoryUsageProcessor;
use PhpNexus\Cwh\Handler\CloudWatch as CloudWatchHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Cache\CacheItemPoolInterface;
use SineMacula\Log\CloudWatch\CloudWatchLoggerFactory;
use SineMacula\Log\CloudWatch\Exceptions\Contracts\LogCloudWatchExceptionInterface;
use SineMacula\Log\CloudWatch\Exceptions\InvalidConfigurationException;
use Tests\TestCase;

/**
 * Tests for the CloudWatchLoggerFactory.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CloudWatchLoggerFactory::class)]
final class CloudWatchLoggerFactoryTest extends TestCase
{
    /**
     * Test that __invoke returns a Monolog Logger instance.
     *
     * @return void
     */
    public function testInvokeReturnsLoggerInstance(): void
    {
        $logger = $this->makeLogger($this->buildConfig());

        self::assertInstanceOf(Logger::class, $logger);
    }

    /**
     * Test that the logger uses the 'cloudwatch' channel name by default.
     *
     * @return void
     */
    public function testLoggerDefaultsToCloudwatchChannelName(): void
    {
        $logger = $this->makeLogger($this->buildConfig());

        self::assertSame('cloudwatch', $logger->getName());
    }

    /**
     * Test that the logger honours a configured channel name.
     *
     * @return void
     */
    public function testLoggerUsesConfiguredChannelName(): void
    {
        $config = $this->buildConfig();

        $config['name'] = 'audit';

        $logger = $this->makeLogger($config);

        self::assertSame('audit', $logger->getName());
    }

    /**
     * Test that the logger has exactly one handler configured.
     *
     * @return void
     */
    public function testLoggerHasOneHandler(): void
    {
        $logger = $this->makeLogger($this->buildConfig());

        self::assertCount(1, $logger->getHandlers());
    }

    /**
     * Test that the handler receives the configured log group, stream,
     * retention, and batch size.
     *
     * @return void
     */
    public function testHandlerReceivesConfiguredGroupStreamRetentionAndBatchSize(): void
    {
        $config = $this->buildConfig();

        $config['retention']  = 14;
        $config['batch_size'] = 500;

        $handler = $this->resolveHandler($config);

        self::assertSame('test-log-group', $this->getHandlerProperty($handler, 'group'));
        self::assertSame('test-log-stream', $this->getHandlerProperty($handler, 'stream'));
        self::assertSame(14, $this->getHandlerProperty($handler, 'retention'));
        self::assertSame(500, $this->getHandlerProperty($handler, 'batchSize'));
    }

    /**
     * Test that the handler falls back to the default retention and batch
     * size when the configuration keys are absent.
     *
     * @return void
     */
    public function testHandlerDefaultsRetentionAndBatchSizeWhenAbsent(): void
    {
        $config = $this->buildConfig();

        unset($config['retention'], $config['batch_size']);

        $handler = $this->resolveHandler($config);

        self::assertSame(7, $this->getHandlerProperty($handler, 'retention'));
        self::assertSame(1000, $this->getHandlerProperty($handler, 'batchSize'));
    }

    /**
     * Test that a non-numeric retention is rejected.
     *
     * @return void
     */
    public function testNonNumericRetentionThrows(): void
    {
        $config = $this->buildConfig();

        $config['retention'] = 'not-a-number';

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that a non-numeric batch size is rejected.
     *
     * @return void
     */
    public function testNonNumericBatchSizeThrows(): void
    {
        $config = $this->buildConfig();

        $config['batch_size'] = 'not-a-number';

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that a non-numeric requests-per-second limit is rejected.
     *
     * @return void
     */
    public function testNonNumericRpsLimitThrows(): void
    {
        $config = $this->buildConfig();

        $config['rps_limit'] = 'not-a-number';

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that an explicit null retention is preserved so a pre-provisioned
     * log group keeps its own retention policy.
     *
     * @return void
     */
    public function testHandlerPreservesNullRetention(): void
    {
        $config = $this->buildConfig();

        $config['retention'] = null;

        $handler = $this->resolveHandler($config);

        self::assertNull($this->getHandlerProperty($handler, 'retention'));
    }

    /**
     * Test that the handler honours the configured logging level.
     *
     * @return void
     */
    public function testHandlerHonoursConfiguredLevel(): void
    {
        $config = $this->buildConfig();

        $config['level'] = 'error';

        $handler = $this->resolveHandler($config);

        self::assertSame(Level::Error, $handler->getLevel());
    }

    /**
     * Test that the handler defaults to the debug logging level when the
     * level key is absent.
     *
     * @return void
     */
    public function testHandlerDefaultsToDebugLevelWhenAbsent(): void
    {
        $config = $this->buildConfig();

        unset($config['level']);

        $handler = $this->resolveHandler($config);

        self::assertSame(Level::Debug, $handler->getLevel());
    }

    /**
     * Test that log group and stream creation default to enabled.
     *
     * @return void
     */
    public function testHandlerCreatesGroupAndStreamByDefault(): void
    {
        $handler = $this->resolveHandler($this->buildConfig());

        self::assertTrue($this->getHandlerProperty($handler, 'createGroup'));
        self::assertTrue($this->getHandlerProperty($handler, 'createStream'));
    }

    /**
     * Test that log group and stream creation can be disabled for
     * least-privilege deployments.
     *
     * @return void
     */
    public function testHandlerGroupAndStreamCreationCanBeDisabled(): void
    {
        $config = $this->buildConfig();

        $config['create_group']  = false;
        $config['create_stream'] = false;

        $handler = $this->resolveHandler($config);

        self::assertFalse($this->getHandlerProperty($handler, 'createGroup'));
        self::assertFalse($this->getHandlerProperty($handler, 'createStream'));
    }

    /**
     * Test that the handler receives configured tags and defaults to none.
     *
     * @return void
     */
    public function testHandlerReceivesConfiguredTagsAndDefaultsToNone(): void
    {
        $handler = $this->resolveHandler($this->buildConfig());

        self::assertSame([], $this->getHandlerProperty($handler, 'tags'));

        $config = $this->buildConfig();

        $config['tags'] = ['Environment' => 'production', 'Team' => 'platform'];

        $handler = $this->resolveHandler($config);

        self::assertSame(['Environment' => 'production', 'Team' => 'platform'], $this->getHandlerProperty($handler, 'tags'));
    }

    /**
     * Test that the handler receives the configured requests-per-second
     * limit, defaulting to zero.
     *
     * @return void
     */
    public function testHandlerReceivesConfiguredRpsLimit(): void
    {
        self::assertSame(0, $this->getHandlerProperty($this->resolveHandler($this->buildConfig()), 'rpsLimit'));

        $config = $this->buildConfig();

        $config['rps_limit'] = 5;

        self::assertSame(5, $this->getHandlerProperty($this->resolveHandler($config), 'rpsLimit'));
    }

    /**
     * Test that no cache pool is configured by default.
     *
     * @return void
     */
    public function testCacheDefaultsToNone(): void
    {
        self::assertNull($this->getHandlerProperty($this->resolveHandler($this->buildConfig()), 'cacheItemPool'));
    }

    /**
     * Test that a cache pool instance is applied to the handler.
     *
     * @return void
     */
    public function testCachePoolInstanceIsApplied(): void
    {
        $config = $this->buildConfig();

        $pool = self::createStub(CacheItemPoolInterface::class);

        $config['cache'] = $pool;

        self::assertSame($pool, $this->getHandlerProperty($this->resolveHandler($config), 'cacheItemPool'));
    }

    /**
     * Test that a cache pool class string is resolved through the container.
     *
     * @return void
     */
    public function testCachePoolClassStringIsResolved(): void
    {
        assert($this->app !== null);

        $pool = self::createStub(CacheItemPoolInterface::class);

        $this->app->instance(CacheItemPoolInterface::class, $pool);

        $config = $this->buildConfig();

        $config['cache'] = CacheItemPoolInterface::class;

        self::assertSame($pool, $this->getHandlerProperty($this->resolveHandler($config), 'cacheItemPool'));
    }

    /**
     * Test that a cache value not implementing the PSR-6 contract is rejected.
     *
     * @return void
     */
    public function testInvalidCacheThrows(): void
    {
        $config = $this->buildConfig();

        $config['cache'] = \stdClass::class;

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that a cache class string that does not exist is rejected with the
     * package exception rather than a container resolution error.
     *
     * @return void
     */
    public function testNonExistentCacheClassThrows(): void
    {
        $config = $this->buildConfig();

        $config['cache'] = 'App\Does\Not\Exist';

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that the cache TTL is applied and defaults to 300 seconds.
     *
     * @return void
     */
    public function testCacheTtlIsAppliedAndDefaults(): void
    {
        $config = $this->buildConfig();

        $config['cache'] = self::createStub(CacheItemPoolInterface::class);

        self::assertSame(300, $this->getHandlerProperty($this->resolveHandler($config), 'cacheItemTtl'));

        $config['cache_ttl'] = 600;

        self::assertSame(600, $this->getHandlerProperty($this->resolveHandler($config), 'cacheItemTtl'));
    }

    /**
     * Test that the logger builds without explicit credentials so the AWS
     * default provider chain is used.
     *
     * @return void
     */
    public function testLoggerBuildsWithoutExplicitCredentials(): void
    {
        $config = $this->buildConfig();

        unset($config['aws']['credentials']);

        self::assertInstanceOf(Logger::class, $this->makeLogger($config));
    }

    /**
     * Test that a formatter instance is applied to the handler.
     *
     * @return void
     */
    public function testFormatterInstanceIsApplied(): void
    {
        $config = $this->buildConfig();

        $formatter = new JsonFormatter;

        $config['formatter'] = $formatter;

        $handler = $this->resolveHandler($config);

        self::assertSame($formatter, $handler->getFormatter());
    }

    /**
     * Test that a formatter class string is resolved and applied.
     *
     * @return void
     */
    public function testFormatterClassStringIsResolved(): void
    {
        $config = $this->buildConfig();

        $config['formatter'] = JsonFormatter::class;

        $handler = $this->resolveHandler($config);

        self::assertInstanceOf(JsonFormatter::class, $handler->getFormatter());
    }

    /**
     * Test that a formatter not implementing the formatter contract is
     * rejected.
     *
     * @return void
     */
    public function testInvalidFormatterThrows(): void
    {
        $config = $this->buildConfig();

        $config['formatter'] = \stdClass::class;

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/\[formatter\]/');

        $this->makeLogger($config);
    }

    /**
     * Test that a formatter class string that does not exist is rejected with
     * the package exception rather than a container resolution error.
     *
     * @return void
     */
    public function testNonExistentFormatterClassThrows(): void
    {
        $config = $this->buildConfig();

        $config['formatter'] = 'App\Does\Not\Exist';

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that configured processors are applied to the logger from both
     * callables and class strings.
     *
     * @return void
     */
    public function testProcessorsAreApplied(): void
    {
        $config = $this->buildConfig();

        $config['processors'] = [
            static fn (LogRecord $record): LogRecord => $record,
            MemoryUsageProcessor::class,
        ];

        $logger = $this->makeLogger($config);

        self::assertCount(2, $logger->getProcessors());

        $resolved = array_filter(
            $logger->getProcessors(),
            static fn (callable $processor): bool => $processor instanceof MemoryUsageProcessor,
        );

        self::assertCount(1, $resolved);
    }

    /**
     * Test that a non-array processors value is rejected.
     *
     * @return void
     */
    public function testNonArrayProcessorsThrow(): void
    {
        $config = $this->buildConfig();

        $config['processors'] = 'not-an-array';

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that a non-array tags value is rejected.
     *
     * @return void
     */
    public function testNonArrayTagsThrow(): void
    {
        $config = $this->buildConfig();

        $config['tags'] = 'not-an-array';

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that a processor that is neither callable nor a resolvable
     * callable class string is rejected.
     *
     * @return void
     */
    public function testInvalidProcessorThrows(): void
    {
        $config = $this->buildConfig();

        $config['processors'] = [\stdClass::class];

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that a missing log group is rejected.
     *
     * @return void
     */
    public function testMissingLogGroupThrows(): void
    {
        $config = $this->buildConfig();

        unset($config['log_group']);

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that a missing log stream is rejected.
     *
     * @return void
     */
    public function testMissingLogStreamThrows(): void
    {
        $config = $this->buildConfig();

        unset($config['log_stream']);

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that a missing AWS region is rejected.
     *
     * @return void
     */
    public function testMissingRegionThrows(): void
    {
        $config = $this->buildConfig();

        unset($config['aws']['region']);

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that configuration exceptions share the package marker contract.
     *
     * @return void
     */
    public function testConfigurationExceptionImplementsPackageContract(): void
    {
        $config = $this->buildConfig();

        unset($config['log_group']);

        try {
            $this->makeLogger($config);
        } catch (InvalidConfigurationException $exception) {
            self::assertInstanceOf(LogCloudWatchExceptionInterface::class, $exception);

            return;
        }

        self::fail('Expected an InvalidConfigurationException to be thrown.');
    }

    /**
     * Test that records bubble by default and that bubbling can be disabled.
     *
     * @return void
     */
    public function testHandlerBubblesByDefaultAndCanBeDisabled(): void
    {
        self::assertTrue($this->getHandlerProperty($this->resolveHandler($this->buildConfig()), 'bubble'));

        $config = $this->buildConfig();

        $config['bubble'] = false;

        self::assertFalse($this->getHandlerProperty($this->resolveHandler($config), 'bubble'));
    }

    /**
     * Test that an empty log group is rejected.
     *
     * @return void
     */
    public function testEmptyLogGroupThrows(): void
    {
        $config = $this->buildConfig();

        $config['log_group'] = '';

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that an empty log stream is rejected.
     *
     * @return void
     */
    public function testEmptyLogStreamThrows(): void
    {
        $config = $this->buildConfig();

        $config['log_stream'] = '';

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that an empty AWS region is rejected.
     *
     * @return void
     */
    public function testEmptyRegionThrows(): void
    {
        $config = $this->buildConfig();

        $config['aws']['region'] = '';

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that a non-string log group is rejected.
     *
     * @return void
     */
    public function testNonStringLogGroupThrows(): void
    {
        $config = $this->buildConfig();

        $config['log_group'] = 123;

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that a level of an unsupported type is rejected.
     *
     * @return void
     */
    public function testInvalidLevelTypeThrows(): void
    {
        $config = $this->buildConfig();

        $config['level'] = ['not', 'a', 'level'];

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that an integer level is accepted.
     *
     * @return void
     */
    public function testIntegerLevelIsAccepted(): void
    {
        $config = $this->buildConfig();

        $config['level'] = Level::Warning->value;

        self::assertSame(Level::Warning, $this->resolveHandler($config)->getLevel());
    }

    /**
     * Test that a Monolog Level instance is accepted.
     *
     * @return void
     */
    public function testLevelEnumIsAccepted(): void
    {
        $config = $this->buildConfig();

        $config['level'] = Level::Notice;

        self::assertSame(Level::Notice, $this->resolveHandler($config)->getLevel());
    }

    /**
     * Test that the client arguments carry the version, region and explicit
     * credentials when supplied.
     *
     * @return void
     */
    public function testClientArgumentsIncludeVersionRegionAndCredentials(): void
    {
        $arguments = $this->clientArguments($this->buildConfig());

        self::assertSame('latest', $arguments['version']);
        self::assertSame('us-east-1', $arguments['region']);
        self::assertArrayHasKey('credentials', $arguments);
    }

    /**
     * Test that the client arguments omit credentials when none are supplied,
     * so the AWS default provider chain is used.
     *
     * @return void
     */
    public function testClientArgumentsOmitCredentialsWhenAbsent(): void
    {
        $config = $this->buildConfig();

        unset($config['aws']['credentials']);

        self::assertArrayNotHasKey('credentials', $this->clientArguments($config));
    }

    /**
     * Test that supplying only a key (without a secret) is rejected, rather
     * than silently falling back to the default provider chain.
     *
     * @return void
     */
    public function testPartialCredentialsWithKeyOnlyThrow(): void
    {
        $config = $this->buildConfig();

        $config['aws']['credentials']['secret'] = '';

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that supplying only a secret (without a key) is rejected.
     *
     * @return void
     */
    public function testPartialCredentialsWithSecretOnlyThrow(): void
    {
        $config = $this->buildConfig();

        $config['aws']['credentials']['key'] = '';

        $this->expectException(InvalidConfigurationException::class);

        $this->makeLogger($config);
    }

    /**
     * Test that the AWS client is configured with the channel region.
     *
     * @return void
     */
    public function testClientUsesConfiguredRegion(): void
    {
        $client = $this->getHandlerProperty($this->resolveHandler($this->buildConfig()), 'client');

        self::assertInstanceOf(CloudWatchLogsClient::class, $client);
        self::assertSame('us-east-1', $client->getRegion());
    }

    /**
     * Build a configuration array for the CloudWatch logger.
     *
     * @return array<string, mixed>
     */
    private function buildConfig(): array
    {
        return [
            'aws' => [
                'region'      => 'us-east-1',
                'credentials' => [
                    'key'    => 'test-key',
                    'secret' => 'test-secret',
                ],
            ],
            'log_group'  => 'test-log-group',
            'log_stream' => 'test-log-stream',
            'retention'  => 7,
            'batch_size' => 1000,
            'level'      => 'debug',
        ];
    }

    /**
     * Build a CloudWatch logger from the given configuration.
     *
     * @param  array<string, mixed>  $config
     * @return \Monolog\Logger
     */
    private function makeLogger(array $config): Logger
    {
        assert($this->app !== null);

        return (new CloudWatchLoggerFactory($this->app))->__invoke($config);
    }

    /**
     * Resolve the AWS client arguments the factory builds for a configuration.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function clientArguments(array $config): array
    {
        assert($this->app !== null);

        $method = new \ReflectionMethod(CloudWatchLoggerFactory::class, 'clientArguments');

        $arguments = $method->invoke(new CloudWatchLoggerFactory($this->app), $config);

        self::assertIsArray($arguments);

        /** @var array<string, mixed> $arguments */
        return $arguments;
    }

    /**
     * Resolve the CloudWatch handler created for the given configuration.
     *
     * @param  array<string, mixed>  $config
     * @return \PhpNexus\Cwh\Handler\CloudWatch
     */
    private function resolveHandler(array $config): CloudWatchHandler
    {
        $handler = $this->makeLogger($config)->getHandlers()[0];

        self::assertInstanceOf(CloudWatchHandler::class, $handler);

        return $handler;
    }

    /**
     * Read a non-public property from the CloudWatch handler.
     *
     * @param  \PhpNexus\Cwh\Handler\CloudWatch  $handler
     * @param  string  $property
     * @return mixed
     */
    private function getHandlerProperty(CloudWatchHandler $handler, string $property): mixed
    {
        $reflection = new \ReflectionProperty(CloudWatchHandler::class, $property);

        return $reflection->getValue($handler);
    }
}
