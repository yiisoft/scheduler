<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Generator;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\TaskContext;

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
        $this->checkpoint = new Checkpoint(
            $schedule->getName(),
            array_map(static fn(RecurringTask $task): string => $task->getId(), $schedule->tasks()),
            $schedule->getState(),
            $schedule->getMutex(),
        );
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

        if (!$this->checkpoint->acquire()) {
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
                    $this->checkpoint->save($task->getId(), $time);
                    continue;
                }

                $context = new TaskContext($this->schedule->getName(), $task->getId(), $task->getTrigger(), $time, $nextTime);

                yield $context => $task;

                // Reached whether the task succeeded or failed: the consumer keeps a failure to
                // itself until the schedule is drained, so a failed run is recorded like any
                // other instead of being emitted again by the next process.
                $this->checkpoint->save($task->getId(), $time);
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
        if (!$this->checkpoint->acquire()) {
            return null;
        }

        try {
            $heap = $this->heap();

            return $heap->isEmpty() ? null : $heap->top()[0];
        } finally {
            $this->checkpoint->release();
        }
    }

    private function heap(): TriggerHeap
    {
        // A position read from the shared state replaces the pending runs: they were computed from
        // a position this process believed to be current, and another one has moved on since.
        if (null !== $this->heap && $this->heapRevision === $this->checkpoint->revision()) {
            return $this->heap;
        }

        $this->heapRevision = $this->checkpoint->revision();
        $this->heap = new TriggerHeap();
        $position = $this->checkpoint->time();

        foreach ($this->schedule->tasks() as $task) {
            $index = $this->indices[$task->getId()] ??= count($this->indices);

            // No record yet — newly added, or left unstarted when a process died — means the
            // position of the schedule, and now when there is no position either.
            $seedTime = $this->checkpoint->lastRun($task->getId()) ?? $position ?? $this->clock->now();

            if (null !== ($nextTime = $task->getTrigger()->getNextRunDate($seedTime))) {
                $this->heap->insert([$nextTime, $index, $task]);
            }
        }

        return $this->heap;
    }
}
