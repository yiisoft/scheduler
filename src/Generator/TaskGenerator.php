<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Generator;

use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Ledger\ExecutionIdentity;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\TaskContext;

use function array_map;
use function count;
use function hash;
use function implode;

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
    /**
     * How long after it was admitted an occurrence is still worth starting again.
     *
     * A constant rather than a setting, because the window belongs to the recovery loop that
     * reads it and not to the record it bounds. It bounds new attempts only: a process
     * already owned is still observed afterwards, since an expired deadline says nothing about
     * whether it stopped.
     */
    private const RECOVERY_WINDOW = 'P1D';

    private ?TriggerHeap $heap = null;
    private int $heapRevision = -1;
    private readonly Checkpoint $checkpoint;
    /**
     * The revision of the configuration this schedule was built from, which is the set of tasks
     * it holds. A deploy that adds, removes or redefines one changes it, and an execution
     * admitted under the old one keeps saying so.
     */
    private readonly string $sourceRevision;
    /** @var array<string, int> Task id to insertion index, ordering the runs of a shared due time. */
    private array $indices = [];

    public function __construct(
        private readonly Schedule $schedule,
        private readonly ClockInterface $clock,
    ) {
        $ids = array_map(static fn(RecurringTask $task): string => $task->getId(), $schedule->tasks());

        $this->checkpoint = new Checkpoint(
            $schedule->getName(),
            $ids,
            $schedule->getState(),
            $schedule->getMutex(),
        );
        $this->sourceRevision = hash('xxh3', implode(',', $ids));
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
                    // Not admitted: a missed run the schedule is configured to skip is never
                    // executed, so there is no execution to keep a record of.
                    $this->checkpoint->save($task->getId(), $time);
                    continue;
                }

                // Before the occurrence is emitted, and so before the checkpoint below moves
                // past it: nothing may acknowledge scheduling progress past an occurrence whose
                // record is not durable yet. A ledger that cannot write throws, the checkpoint
                // is not reached, and the occurrence stays due for the next process.
                $this->admit($task->getId(), $time, $now);

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

    /**
     * Writes the record of an occurrence about to be emitted, where there is a ledger to write
     * it to.
     *
     * Idempotent by identity, so a process that died between this and the checkpoint below it
     * admits the same occurrence again on the next pass and finds what it already wrote.
     */
    private function admit(string $taskId, DateTimeImmutable $occurrence, DateTimeImmutable $now): void
    {
        $ledger = $this->schedule->getLedger();

        if (null === $ledger) {
            return;
        }

        $ledger->admit(
            new ExecutionIdentity($this->schedule->getName(), $taskId, $occurrence),
            $this->sourceRevision,
            $now,
            $now->add(new DateInterval(self::RECOVERY_WINDOW)),
        );
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

        if (null === $position) {
            // Nothing stored yet, so the runs about to be computed are the first this schedule
            // has ever had. Where they are computed from is written down before any of them is
            // emitted: a tick interrupted before it drains then leaves the runs it never started
            // behind a position, rather than behind nothing for the next process to seed from.
            $position = $this->clock->now();
            $this->checkpoint->markInitialPosition($position);
        }

        foreach ($this->schedule->tasks() as $task) {
            $index = $this->indices[$task->getId()] ??= count($this->indices);

            // No record yet — newly added, or left unstarted when a process died — means the
            // position of the schedule.
            $seedTime = $this->checkpoint->lastRun($task->getId()) ?? $position;

            if (null !== ($nextTime = $task->getTrigger()->getNextRunDate($seedTime))) {
                $this->heap->insert([$nextTime, $index, $task]);
            }
        }

        return $this->heap;
    }
}
