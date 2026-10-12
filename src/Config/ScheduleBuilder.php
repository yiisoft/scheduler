<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Config;

use DateTimeImmutable;
use Yiisoft\Schedule\Executor\Command;
use Yiisoft\Schedule\ExecutionMode;
use DateTimeZone;
use Yiisoft\Schedule\ScalarMetadata;

final class ScheduleBuilder
{
    public readonly TriggerDefinition $trigger;

    public function __construct(
        private readonly SchedulerConfig $owner,
        public readonly Command $command,
        private readonly int $ordinal,
        public readonly ExecutionMode $mode = ExecutionMode::ASYNC,
        public readonly ?TimeoutPolicy $timeout = null,
        private readonly string $taskIdSuffix = '',
        public readonly MissedOccurrenceSetting $missedOccurrences = new MissedOccurrenceSetting(),
        public readonly OverlapPolicy $overlap = OverlapPolicy::ALLOW,
        ?TriggerDefinition $trigger = null,
        public readonly array $metadata = [],
    ) {
        ScalarMetadata::assertAggregateSupported($metadata);
        $this->trigger = $trigger ?? new TriggerDefinition('periodic');
    }

    public function sync(): self
    {
        return $this->copy(mode: ExecutionMode::SYNC);
    }

    public function async(): self
    {
        return $this->copy(mode: ExecutionMode::ASYNC);
    }

    public function timeout(float $soft, ?float $hard = null): self
    {
        return $this->copy(timeout: new TimeoutPolicy($soft, $hard));
    }

    public function id(string $id): self
    {
        return $this->copy(taskIdSuffix: $id);
    }

    public function missedOccurrences(MissedOccurrencePolicy $policy): self
    {
        return $this->copy(missed: new MissedOccurrenceSetting($policy));
    }

    public function repeatMissedLast(): self
    {
        return $this->missedOccurrences(MissedOccurrencePolicy::LAST);
    }

    public function repeatMissedAll(int $max): self
    {
        return $this->copy(missed: new MissedOccurrenceSetting(MissedOccurrencePolicy::ALL, $max));
    }

    public function overlap(OverlapPolicy|string $policy): self
    {
        return $this->copy(overlap: OverlapPolicy::fromScalar($policy));
    }

    /** Configure a trigger using a registered reference and scalar definition. */
    public function trigger(string $reference, array $definition = []): self
    {
        return $this->copy(trigger: new TriggerDefinition($reference, $definition));
    }

    /** Configure a fixed-second portable trigger. */
    public function every(int $seconds, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): self
    {
        return $this->copy(trigger: new TriggerDefinition('periodic', [
            'type' => 'periodic', 'seconds' => $seconds,
            'from' => $from?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
            'until' => $until?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
        ]));
    }

    /** Configure a portable cron trigger. */
    public function cron(string $expression, ?string $timezone = null, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): self
    {
        return $this->copy(trigger: new TriggerDefinition('cron', [
            'type' => 'cron', 'expression' => $expression, 'timezone' => $timezone,
            'from' => $from?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
            'until' => $until?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
        ]));
    }

    public function metadata(array $metadata): self
    {
        ScalarMetadata::assertAggregateSupported($metadata);
        return $this->copy(metadata: $metadata);
    }

    public function taskId(): string
    {
        return $this->taskIdSuffix !== '' ? $this->taskIdSuffix : 'task-' . substr(hash('sha256', $this->command::class . ':' . $this->ordinal), 0, 24);
    }

    public function taskIdSuffix(): string
    {
        return $this->taskIdSuffix;
    }

    private function copy(?ExecutionMode $mode = null, ?TimeoutPolicy $timeout = null, ?string $taskIdSuffix = null, ?MissedOccurrenceSetting $missed = null, ?OverlapPolicy $overlap = null, ?TriggerDefinition $trigger = null, ?array $metadata = null): self
    {
        return $this->replace(new self($this->owner, $this->command, $this->ordinal, $mode ?? $this->mode, $timeout ?? $this->timeout, $taskIdSuffix ?? $this->taskIdSuffix, $missed ?? $this->missedOccurrences, $overlap ?? $this->overlap, $trigger ?? $this->trigger, $metadata ?? $this->metadata));
    }

    private function replace(self $copy): self
    {
        $this->owner->replace($this->ordinal, $copy);
        return $copy;
    }
}
