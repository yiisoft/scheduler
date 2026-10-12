<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * The current time from the system clock.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
