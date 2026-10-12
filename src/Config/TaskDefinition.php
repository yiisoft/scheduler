<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Config;

use Yiisoft\Schedule\Executor\Command;
use Yiisoft\Schedule\ExecutionMode;
use Yiisoft\Schedule\ScalarMetadata;
use LogicException;
use Yiisoft\Schedule\Task\{ScheduleId, TaskId};

final class TaskDefinition
{
    public readonly ScheduleId $scheduleId;
    public readonly TriggerDefinition $trigger;
    public readonly MissedOccurrenceSetting $missedOccurrences;
    public readonly OverlapPolicy $overlap;

    public function __construct(
        public readonly TaskId $id,
        public readonly Command $command,
        public readonly ExecutionMode $mode = ExecutionMode::ASYNC,
        public readonly ?TimeoutPolicy $timeout = null,
        public readonly array $metadata = [],
        ?ScheduleId $scheduleId = null,
        ?TriggerDefinition $trigger = null,
        mixed $missedOccurrences = MissedOccurrencePolicy::SKIP,
        mixed $overlap = OverlapPolicy::ALLOW,
    ) {
        $this->missedOccurrences = $missedOccurrences instanceof MissedOccurrenceSetting
            ? $missedOccurrences
            : ($missedOccurrences instanceof MissedOccurrencePolicy
                ? new MissedOccurrenceSetting($missedOccurrences)
                : MissedOccurrenceSetting::fromScalar($missedOccurrences));
        $this->overlap = OverlapPolicy::fromScalar($overlap);
        $this->scheduleId = $scheduleId ?? new ScheduleId('default');
        $this->trigger = $trigger ?? new TriggerDefinition('default');
        ScalarMetadata::assertAggregateSupported($this->metadata, $this->trigger->definition);
    }

    public function __serialize(): array
    {
        throw new LogicException('Task definitions cannot be serialized.');
    }

    public function id(): TaskId
    {
        return $this->id;
    }

    public function executionMode(): ExecutionMode
    {
        return $this->mode;
    }

    public function timeoutPolicy(): ?TimeoutPolicy
    {
        return $this->timeout;
    }

    public function overlapPolicy(): OverlapPolicy
    {
        return $this->overlap;
    }
}
