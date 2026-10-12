<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

final class SystemMonotonicClock implements MonotonicClockInterface
{
    public function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
