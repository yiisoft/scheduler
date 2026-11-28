<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Generator;

use DateTimeImmutable;
use SplHeap;
use Yiisoft\Scheduler\RecurringTask;

/**
 * A min-heap of pending task runs, ordered by run date and then by insertion index,
 * so simultaneous runs keep a deterministic order.
 *
 * @internal
 *
 * @extends SplHeap<array{DateTimeImmutable, int, RecurringTask, bool}>
 */
final class TriggerHeap extends SplHeap
{
    /**
     * @param array{DateTimeImmutable, int, RecurringTask, bool} $value1
     * @param array{DateTimeImmutable, int, RecurringTask, bool} $value2
     */
    protected function compare(mixed $value1, mixed $value2): int
    {
        return $value2[0] <=> $value1[0] ?: $value2[1] <=> $value1[1];
    }
}
