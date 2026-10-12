<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

interface MonotonicClockInterface
{
    public function now(): float;
}
