<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Event;

use Throwable;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\TaskContext;

/**
 * Dispatched when a task throws. Ignoring lets the scheduler continue with the next tasks;
 * otherwise the error is rethrown.
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
