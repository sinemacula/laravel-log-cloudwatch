# Laravel Log CloudWatch

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-log-cloudwatch.svg)](https://packagist.org/packages/sinemacula/laravel-log-cloudwatch)
[![Build Status](https://github.com/sinemacula/laravel-log-cloudwatch/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-log-cloudwatch/actions/workflows/tests.yml)
[![Quality Gates](https://github.com/sinemacula/laravel-log-cloudwatch/actions/workflows/quality-gates.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-log-cloudwatch/actions/workflows/quality-gates.yml)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-log-cloudwatch/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-log-cloudwatch)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-log-cloudwatch/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-log-cloudwatch)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-log-cloudwatch.svg)](https://packagist.org/packages/sinemacula/laravel-log-cloudwatch)

A CloudWatch Monolog log driver for Laravel. Registers a `cloudwatch` custom log channel that ships log records to AWS
CloudWatch Logs via [phpnexus/cwh](https://github.com/phpnexus/cwh), so application logs land in CloudWatch without any
bespoke wiring.

The driver is registered automatically through Laravel's package auto-discovery; add a `cloudwatch` channel to your
logging config (see below) to start shipping logs.

## How It Works

The package registers a `cloudwatch` driver with Laravel's log manager through `LogCloudWatchServiceProvider`. When a
channel configured with `'driver' => 'cloudwatch'` is resolved, the driver builds a Monolog `Logger` backed by a single
[phpnexus/cwh](https://github.com/phpnexus/cwh) CloudWatch handler, constructed from the channel's settings.

A few rules hold:

- **Standard Laravel logging.** Once configured, the channel behaves like any other Laravel log channel: use it
  directly, inside a `stack`, or as the default `LOG_CHANNEL`.
- **Batched delivery.** Records are buffered and flushed in batches (`batch_size`) to keep CloudWatch API calls
  efficient, and the log group's retention is set from `retention`.

## Installation

```bash
composer require sinemacula/laravel-log-cloudwatch
```

The service provider is auto-discovered.

## Configuration

Add a `cloudwatch` channel to your `config/logging.php`:

```php
'cloudwatch' => [
    'driver'     => 'cloudwatch',
    'aws'        => [
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'log_group'  => env('CLOUDWATCH_LOG_GROUP', '/app/laravel'),
    'log_stream' => env('CLOUDWATCH_LOG_STREAM', 'application'),
    'retention'  => env('CLOUDWATCH_LOG_RETENTION', 7),
    'batch_size' => env('CLOUDWATCH_LOG_BATCH_SIZE', 1000),
    'level'      => env('LOG_LEVEL', 'debug'),
],
```

This example relies on the AWS default credential provider chain (an IAM role or instance profile), which is the
recommended setup. To supply static credentials instead, see [Credentials](#credentials).

| Key               | Description                                                                                                  | Default         |
|-------------------|--------------------------------------------------------------------------------------------------------------|-----------------|
| `driver`          | Must be `cloudwatch` to resolve this driver.                                                                 | -               |
| `name`            | Monolog channel name used in formatted output.                                                               | `cloudwatch`    |
| `aws.region`      | AWS region for the CloudWatch Logs client. **Required.**                                                     | -               |
| `aws.credentials` | Static credentials. Supply both `key` and `secret`, or neither (uses the default provider chain). See below. | -               |
| `log_group`       | The CloudWatch log group log records are written to. **Required.**                                           | -               |
| `log_stream`      | The log stream within the group. **Required.**                                                               | -               |
| `retention`       | Days to retain log events; `null` leaves the existing policy untouched. Applied only when the group is made. | `7`             |
| `batch_size`      | Number of records buffered before a flush to CloudWatch (max `10000`).                                       | `1000`          |
| `level`           | Minimum Monolog level the handler will record.                                                               | `debug`         |
| `create_group`    | Whether to create the log group if it does not exist.                                                        | `true`          |
| `create_stream`   | Whether to create the log stream if it does not exist.                                                       | `true`          |
| `tags`            | Tags applied to the log group when it is created.                                                            | `[]`            |
| `rps_limit`       | Requests-per-second limit before a one-second sleep is triggered; `0` disables.                              | `0`             |
| `bubble`          | Whether records bubble to lower-priority handlers in a `stack`.                                              | `true`          |
| `cache`           | A PSR-6 `Psr\Cache\CacheItemPoolInterface` instance or class string to cache group/stream existence (below). | -               |
| `cache_ttl`       | TTL in seconds for cached group/stream existence checks.                                                     | `300`           |
| `formatter`       | A `Monolog\Formatter\FormatterInterface` instance or class string.                                           | handler default |
| `processors`      | Monolog processors (callables or class strings) added to the channel.                                        | `[]`            |
| `tap`             | Classes that receive the channel to customise it (applied by Laravel).                                       | `[]`            |

### Credentials

Prefer the AWS default credential provider chain - IAM roles, instance profiles, ECS task roles, or environment
variables - which the example above uses by omitting `aws.credentials` entirely. This keeps long-lived secrets out of
your application config and is the recommended production setup.

To supply static credentials instead, add an `aws.credentials` block with **both** a `key` and a `secret` (supplying
only one is rejected):

```php
'aws' => [
    'region'      => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'credentials' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
    ],
],
```

Static credentials live in the channel config array, which can surface in stack traces captured by error trackers. Set
`zend.exception_ignore_args=1` in your production `php.ini` to keep argument values out of traces, or prefer the
default provider chain above.

### Least-privilege deployments

When the log group is provisioned out of band (Terraform, CDK) and the application's IAM role is scoped to only
`logs:PutLogEvents` / `logs:CreateLogStream`, disable group creation and retention management so the handler does not
attempt calls it is not permitted to make:

```php
'create_group'  => false,
'create_stream' => false,
'retention'     => null,
```

### Structured logging

Set a `formatter` to ship structured records - JSON works well with CloudWatch Logs Insights:

```php
'formatter' => Monolog\Formatter\JsonFormatter::class,
```

### Caching group/stream existence

Under PHP-FPM, each request re-checks (and creates, if enabled) the log group and stream on its first write. Supply a
PSR-6 cache pool to share those existence checks across requests and avoid the repeated API calls:

```php
'cache'     => App\Logging\CloudWatchCachePool::class, // a Psr\Cache\CacheItemPoolInterface
'cache_ttl' => 300,
```

A cache pool cannot be combined with both `create_group` and `create_stream` disabled - there would be nothing to
cache.

## Usage

Point a log channel at the driver: set `LOG_CHANNEL=cloudwatch` in your `.env`, or include `cloudwatch` in a `stack`
channel, then log as usual:

```php
use Illuminate\Support\Facades\Log;

Log::info('User logged in', ['id' => $user->id]);

Log::channel('cloudwatch')->error('Payment failed', ['order' => $order->id]);
```

## Requirements

- PHP ^8.3
- Laravel ^12.0

## Testing

```bash
composer test                # PHPUnit suite in parallel via Paratest
composer test:coverage       # suite with Clover coverage output
composer test:mutation       # Infection mutation gate (min MSI 90)
composer test:mutation:full  # full mutation suite without thresholds
composer check               # static analysis and lint via qlty
composer format              # format via qlty
composer smells              # duplication / complexity smells via qlty
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of notable changes.

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on branching, commits, code
quality, and pull requests.

## Security

If you discover a security vulnerability, please report it responsibly. See [SECURITY.md](SECURITY.md) for the
disclosure policy and contact details.

## License

Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
