<?php

declare(strict_types = 1);

namespace SineMacula\Log\CloudWatch;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Illuminate\Contracts\Container\Container;
use Monolog\Formatter\FormatterInterface;
use Monolog\Level;
use Monolog\Logger;
use PhpNexus\Cwh\Handler\CloudWatch as CloudWatchHandler;
use Psr\Cache\CacheItemPoolInterface;
use SineMacula\Log\CloudWatch\Exceptions\InvalidConfigurationException;

/**
 * CloudWatch Logger Factory.
 *
 * Builds a Monolog logger backed by a single phpnexus/cwh CloudWatch handler
 * from a Laravel log channel configuration.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CloudWatchLoggerFactory
{
    /**
     * The container instance.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    private readonly Container $container;

    /**
     * Create a new CloudWatch logger factory.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Create a custom Monolog instance.
     *
     * @param  array<string, mixed>  $config
     * @return \Monolog\Logger
     *
     * @throws \SineMacula\Log\CloudWatch\Exceptions\InvalidConfigurationException
     */
    public function __invoke(array $config): Logger
    {
        $this->validate($config);

        $handler = new CloudWatchHandler(
            $this->makeClient($config),
            $this->stringConfig($config, 'log_group'),
            $this->stringConfig($config, 'log_stream'),
            $this->resolveRetention($config),
            $this->intConfig($config, 'batch_size', 1000),
            $this->resolveTags($config),
            $this->resolveLevel($config),
            (bool) ($config['bubble'] ?? true),
            (bool) ($config['create_group'] ?? true),
            (bool) ($config['create_stream'] ?? true),
            $this->intConfig($config, 'rps_limit', 0),
            $this->resolveCache($config),
            $this->intConfig($config, 'cache_ttl', 300),
        );

        $formatter = $this->resolveFormatter($config);

        if ($formatter !== null) {
            $handler->setFormatter($formatter);
        }

        return new Logger(
            is_string($config['name'] ?? null) ? $config['name'] : 'cloudwatch',
            [$handler],
            $this->resolveProcessors($config),
        );
    }

    /**
     * Ensure the channel configuration contains the required values.
     *
     * @param  array<string, mixed>  $config
     * @return void
     *
     * @throws \SineMacula\Log\CloudWatch\Exceptions\InvalidConfigurationException
     */
    private function validate(array $config): void
    {
        foreach (['log_group', 'log_stream'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
                throw new InvalidConfigurationException("The CloudWatch log channel requires a non-empty string [{$key}] value.");
            }
        }

        if (!isset($config['aws']['region']) || !is_string($config['aws']['region']) || $config['aws']['region'] === '') {
            throw new InvalidConfigurationException('The CloudWatch log channel requires a non-empty string [aws.region] value.');
        }

        // Partial credentials are almost always a mistake; require both halves
        // or neither, so a typo cannot silently fall back to a different
        // identity via the default provider chain.
        if (!empty($config['aws']['credentials']['key']) !== !empty($config['aws']['credentials']['secret'])) {
            throw new InvalidConfigurationException('The CloudWatch log channel [aws.credentials] requires both [key] and [secret], or neither (the AWS default provider chain).');
        }
    }

    /**
     * Build the CloudWatch Logs client from the channel configuration.
     *
     * @param  array<string, mixed>  $config
     * @return \Aws\CloudWatchLogs\CloudWatchLogsClient
     */
    private function makeClient(array $config): CloudWatchLogsClient
    {
        return new CloudWatchLogsClient($this->clientArguments($config));
    }

    /**
     * Build the AWS client arguments from the channel configuration.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function clientArguments(array $config): array
    {
        $arguments = [
            'version' => 'latest',
            'region'  => $config['aws']['region'],
        ];

        // Only pass explicit credentials when supplied; otherwise the AWS SDK
        // resolves them from its default provider chain (IAM roles, instance
        // profiles, environment variables). validate() has already guaranteed
        // that the key and secret are supplied together, so checking the key
        // alone is sufficient here.
        if (!empty($config['aws']['credentials']['key'])) {
            $arguments['credentials'] = $config['aws']['credentials'];
        }

        return $arguments;
    }

    /**
     * Resolve the log group retention period.
     *
     * A null retention is honoured so a pre-provisioned group keeps its own
     * policy; an absent key falls back to the package default.
     *
     * @param  array<string, mixed>  $config
     * @return int|null
     *
     * @throws \SineMacula\Log\CloudWatch\Exceptions\InvalidConfigurationException
     */
    private function resolveRetention(array $config): ?int
    {
        if (!array_key_exists('retention', $config)) {
            return 7;
        }

        if ($config['retention'] === null) {
            return null;
        }

        if (!is_numeric($config['retention'])) {
            throw new InvalidConfigurationException('The CloudWatch log channel [retention] must be numeric or null.');
        }

        return (int) $config['retention'];
    }

    /**
     * Resolve the configured log level.
     *
     * @param  array<string, mixed>  $config
     * @return \Monolog\Level
     *
     * @throws \SineMacula\Log\CloudWatch\Exceptions\InvalidConfigurationException
     */
    private function resolveLevel(array $config): Level
    {
        $level = $config['level'] ?? 'debug';

        if (!is_string($level) && !is_int($level) && !$level instanceof Level) {
            throw new InvalidConfigurationException('The CloudWatch log channel [level] must be a string, integer, or ' . Level::class . ' instance.');
        }

        return Logger::toMonologLevel($level);
    }

    /**
     * Resolve the log group tags.
     *
     * @param  array<string, mixed>  $config
     * @return array<mixed>
     *
     * @throws \SineMacula\Log\CloudWatch\Exceptions\InvalidConfigurationException
     */
    private function resolveTags(array $config): array
    {
        $tags = $config['tags'] ?? [];

        if (!is_array($tags)) {
            throw new InvalidConfigurationException('The CloudWatch log channel [tags] must be an array.');
        }

        return $tags;
    }

    /**
     * Resolve the handler formatter, if one is configured.
     *
     * @param  array<string, mixed>  $config
     * @return \Monolog\Formatter\FormatterInterface|null
     *
     * @throws \SineMacula\Log\CloudWatch\Exceptions\InvalidConfigurationException
     */
    private function resolveFormatter(array $config): ?FormatterInterface
    {
        if (!isset($config['formatter'])) {
            return null;
        }

        $formatter = $config['formatter'];

        if ($formatter instanceof FormatterInterface) {
            return $formatter;
        }

        if (is_string($formatter) && class_exists($formatter)) {
            $resolved = $this->container->make($formatter);

            if ($resolved instanceof FormatterInterface) {
                return $resolved;
            }
        }

        throw new InvalidConfigurationException('The CloudWatch log channel [formatter] must be a class string or instance of ' . FormatterInterface::class . '.');
    }

    /**
     * Resolve the configured Monolog processors.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, callable>
     *
     * @throws \SineMacula\Log\CloudWatch\Exceptions\InvalidConfigurationException
     *
     * @phpstan-ignore missingType.callable
     */
    private function resolveProcessors(array $config): array
    {
        $processors = $config['processors'] ?? [];

        if (!is_array($processors)) {
            throw new InvalidConfigurationException('The CloudWatch log channel [processors] must be an array.');
        }

        return array_values(array_map(
            fn (mixed $processor): callable => $this->resolveProcessor($processor),
            $processors,
        ));
    }

    /**
     * Resolve a single Monolog processor from a callable or class string.
     *
     * @param  mixed  $processor
     * @return callable
     *
     * @throws \SineMacula\Log\CloudWatch\Exceptions\InvalidConfigurationException
     *
     * @phpstan-ignore missingType.callable
     */
    private function resolveProcessor(mixed $processor): callable
    {
        if (is_string($processor) && class_exists($processor)) {
            $processor = $this->container->make($processor);
        }

        if (is_callable($processor)) {
            return $processor;
        }

        throw new InvalidConfigurationException('Each CloudWatch log channel [processor] must be callable or a class string resolving to a callable.');
    }

    /**
     * Resolve the PSR-6 cache pool, if one is configured.
     *
     * The pool lets the handler cache log group/stream existence checks across
     * handler instances (e.g. across PHP-FPM requests), avoiding a repeated
     * describe/create call on the first write of each request.
     *
     * @param  array<string, mixed>  $config
     * @return \Psr\Cache\CacheItemPoolInterface|null
     *
     * @throws \SineMacula\Log\CloudWatch\Exceptions\InvalidConfigurationException
     */
    private function resolveCache(array $config): ?CacheItemPoolInterface
    {
        if (!isset($config['cache'])) {
            return null;
        }

        $cache = $config['cache'];

        if ($cache instanceof CacheItemPoolInterface) {
            return $cache;
        }

        if (is_string($cache) && ($this->container->bound($cache) || class_exists($cache))) {
            $resolved = $this->container->make($cache);

            if ($resolved instanceof CacheItemPoolInterface) {
                return $resolved;
            }
        }

        throw new InvalidConfigurationException('The CloudWatch log channel [cache] must be a class string or instance of ' . CacheItemPoolInterface::class . '.');
    }

    /**
     * Resolve a string configuration value that validate() has guaranteed to be
     * a non-empty string.
     *
     * @param  array<string, mixed>  $config
     * @param  string  $key
     * @return string
     */
    private function stringConfig(array $config, string $key): string
    {
        return is_string($config[$key]) ? $config[$key] : '';
    }

    /**
     * Resolve an integer configuration value, falling back to the default when
     * the value is missing or null, and rejecting non-numeric values.
     *
     * @param  array<string, mixed>  $config
     * @param  string  $key
     * @param  int  $default
     * @return int
     *
     * @throws \SineMacula\Log\CloudWatch\Exceptions\InvalidConfigurationException
     */
    private function intConfig(array $config, string $key, int $default): int
    {
        if (!array_key_exists($key, $config) || $config[$key] === null) {
            return $default;
        }

        if (!is_numeric($config[$key])) {
            throw new InvalidConfigurationException("The CloudWatch log channel [{$key}] must be numeric.");
        }

        return (int) $config[$key];
    }
}
