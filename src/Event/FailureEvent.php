<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Event;

use Throwable;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\TaskContext;

/**
 * Dispatched when a task throws. The remaining due tasks run either way. An ignored failure
 * is only logged; any other is reported once they did, the way the method of the scheduler
 * that ran the task says.
 */
final class FailureEvent
{
    private bool $ignored = false;

    public function __construct(
        public readonly TaskContext $context,
        public readonly RecurringTask $task,
        public readonly Throwable $error,
    ) {}

    public function isIgnored(): bool
    {
        return $this->ignored;
    }

    public function ignore(): void
    {
        $this->ignored = true;
    }
}
