<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Event;

use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\TaskContext;

/**
 * Dispatched after a task ran successfully.
 */
final class PostRunEvent
{
    public function __construct(
        public readonly TaskContext $context,
        public readonly RecurringTask $task,
        public readonly mixed $result,
    ) {}
}
