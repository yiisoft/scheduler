<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

interface PollWaiterInterface
{
    public function wait(float $seconds): void;
}
