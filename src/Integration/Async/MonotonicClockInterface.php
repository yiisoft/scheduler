<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

interface MonotonicClockInterface
{
    public function now(): float;
}
