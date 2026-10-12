<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

interface PollWaiterInterface
{
    public function wait(float $seconds): void;
}
