<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use function max;
use function usleep;

final class UsleepPollWaiter implements PollWaiterInterface
{
    public function wait(float $seconds): void
    {
        $microseconds = $seconds >= 1.0
            ? 1_000_000
            : max(1, (int) ($seconds * 1_000_000));
        usleep($microseconds);
    }
}
