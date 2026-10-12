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

    /**
     * Keeps the error from being rethrown. The daemon loop, Scheduler::run(), does not rethrow
     * it either way, so that one failing task does not stop the whole schedule. An error that
     * is not rethrown is logged.
     */
    public function ignore(): void
    {
        $this->ignored = true;
    }
}
