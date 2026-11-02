<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as CacheInvalidArgumentException;

/**
 * What a PSR-16 cache throws for a key it cannot store.
 */
final class InvalidCacheKeyException extends InvalidArgumentException implements CacheInvalidArgumentException {}
