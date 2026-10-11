<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Event;

use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\TaskContext;

/**
 * Dispatched before a due task runs. Cancelling skips this run only.
 */
final class PreRunEvent
{
    private bool $cancelled = false;

    public function __construct(
        public readonly TaskContext $context,
        public readonly RecurringTask $task,
    ) {}

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }
}
