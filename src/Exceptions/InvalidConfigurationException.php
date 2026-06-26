<?php

declare(strict_types = 1);

namespace SineMacula\Log\CloudWatch\Exceptions;

use SineMacula\Log\CloudWatch\Exceptions\Contracts\LogCloudWatchExceptionInterface;

/**
 * Exception thrown when the CloudWatch log channel configuration is invalid.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class InvalidConfigurationException extends \InvalidArgumentException implements LogCloudWatchExceptionInterface {}
