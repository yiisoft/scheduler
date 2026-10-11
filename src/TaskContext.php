<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

use DateTimeImmutable;
use Yiisoft\Scheduler\Trigger\TriggerInterface;

/**
 * The context of a single task run: which task, when it was triggered and when the next run is due.
 *
 * @psalm-immutable
 */
final class TaskContext
{
    public function __construct(
        public readonly string $scheduleName,
        public readonly string $taskId,
        public readonly TriggerInterface $trigger,
        public readonly DateTimeImmutable $triggeredAt,
        public readonly ?DateTimeImmutable $nextTriggerAt,
    ) {}
}
