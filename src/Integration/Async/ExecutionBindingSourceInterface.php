<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

/** Application-owned port that creates the immutable binding for an occurrence. */
interface ExecutionBindingSourceInterface
{
    public function map(string $scheduleKey, string $taskKey, string $occurrenceId, int $occurrenceTime): ExecutionBinding;
}
