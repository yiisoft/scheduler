<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Generator;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\TaskContext;
use Yiisoft\Scheduler\TaskStatus;

use function array_map;
use function count;

/**
 * Computes which tasks of a schedule are due, in run order.
 *
 * A min-heap of pending runs, each task seeded from the record of its own last emitted run, so
 * a process that was down re-discovers the runs it missed and leaves the ones already emitted.
 *
 * @internal
 */
final class TaskGenerator
{
    private ?TriggerHeap $heap = null;
    private int $heapRevision = -1;
    private readonly Checkpoint $checkpoint;
    /** @var array<string, int> Task id to insertion index, ordering the runs of a shared due time. */
    private array $indices = [];

    public function __construct(
        private readonly Schedule $schedule,
        private readonly ClockInterface $clock,
    ) {
        $this->checkpoint = new Checkpoint($schedule->getName(), $schedule->getState(), $schedule->getMutex());
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    /**
     * Yields the due tasks as "TaskContext => RecurringTask" pairs.
     *
     * @return iterable<TaskContext, RecurringTask>
     */
    public function getTasks(): iterable
    {
        $now = $this->clock->now();

        if (!$this->checkpoint->acquire($this->taskIds())) {
            return;
        }

        try {
            $heap = $this->heap();

            while (!$heap->isEmpty() && $heap->top()[0] <= $now) {
                /** @var array{DateTimeImmutable, int, RecurringTask} $entry */
                $entry = $heap->extract();
                [$time, $index, $task] = $entry;

                $yield = true;
                $nextTime = $task->getTrigger()->getNextRunDate($time);

                if (null !== $nextTime) {
                    if ($nextTime <= $time) {
                        throw new LogicException('A trigger must return a date strictly after the given one.');
                    }

                    $heap->insert([$nextTime, $index, $task]);

                    if ($nextTime <= $now && $this->schedule->shouldProcessOnlyLastMissedRun()) {
                        // A newer missed run exists; only the latest one fires.
                        $yield = false;
                    }
                }

                if (!$yield) {
                    $this->checkpoint->write($task->getId(), $time, TaskStatus::Skipped);
                    continue;
                }

                $context = new TaskContext($this->schedule->getName(), $task->getId(), $task->getTrigger(), $time, $nextTime);

                // Taken rather than due for anyone reading the schedule while this run is in
                // flight. resolve() says what became of it, release() gives it back.
                $this->checkpoint->write($task->getId(), $time, TaskStatus::Claimed, at: $now);

                yield $context => $task;
            }

            // Reached only by a tick that drained, so a process that died partway leaves the
            // runs it never started due.
            $this->checkpoint->markTick($now);
        } finally {
            $this->checkpoint->release();
        }
    }

    /**
     * The soonest pending run of the schedule, if any.
     *
     * The daemon loop sleeps until this date instead of polling on a fixed interval. When the
     * schedule mutex is held by another process, null is returned: that process is the one
     * driving the schedule, so there is nothing here to wake up for.
     */
    public function getNextRunDate(): ?DateTimeImmutable
    {
        if (!$this->checkpoint->acquire($this->taskIds())) {
            return null;
        }

        try {
            $heap = $this->heap();

            return $heap->isEmpty() ? null : $heap->top()[0];
        } finally {
            $this->checkpoint->release();
        }
    }

    /** Records what became of a claimed run, once the consumer knows. */
    public function resolve(TaskContext $context, TaskStatus $status): void
    {
        $this->checkpoint->resolve($context->taskId, $context->triggeredAt, $status);
    }

    /**
     * Gives a claimed run back, leaving it due. For a run known not to have been started at all,
     * as opposed to one whose outcome is merely unknown.
     */
    public function release(TaskContext $context): void
    {
        $this->checkpoint->giveBack($context->taskId, $context->triggeredAt);
    }

    /**
     * @return list<string>
     */
    private function taskIds(): array
    {
        return array_map(static fn(RecurringTask $task): string => $task->getId(), $this->schedule->tasks());
    }

    private function heap(): TriggerHeap
    {
        $now = $this->clock->now();
        $reclaimAfter = $this->schedule->getReclaimAfter();

        // State read back replaces the pending runs: another process has moved on since they were
        // computed. So does a claim there is no longer any point in waiting on, which changes
        // where its task resumes without changing anything that was stored.
        if (null !== $this->heap
            && $this->heapRevision === $this->checkpoint->revision()
            && !$this->checkpoint->hasReclaimableClaim($now, $reclaimAfter)
        ) {
            return $this->heap;
        }

        $this->heapRevision = $this->checkpoint->revision();
        $this->heap = new TriggerHeap();
        $position = $this->checkpoint->time();

        foreach ($this->schedule->tasks() as $task) {
            $index = $this->indices[$task->getId()] ??= count($this->indices);

            // No record yet — newly added, or added while the schedule was down — means the
            // position of the schedule, and now when there is no position either.
            $seedTime = $this->checkpoint->resumePoint($task->getId(), $now, $reclaimAfter)
                ?? $position
                ?? $now;

            if (null !== ($nextTime = $task->getTrigger()->getNextRunDate($seedTime))) {
                $this->heap->insert([$nextTime, $index, $task]);
            }
        }

        return $this->heap;
    }
}
