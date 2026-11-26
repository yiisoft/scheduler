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
use Yiisoft\Scheduler\TaskStatus;

use function array_map;
use function count;
use function end;
use function hash;
use function implode;
use function sprintf;
use function strlen;

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

    /**
     * How many occurrences of one task a single tick walks before it leaves the rest for the
     * next one.
     *
     * A trigger that implements `TriggerRangeInterface` can be asked for a bounded part of its
     * sequence, but one that implements `TriggerInterface` alone can only be walked a date at
     * a time, and the walk is what a long gap makes expensive: a task due every minute
     * whose last run was a year ago is a year of `getNextRunDate()` calls before the first one
     * is emitted. Stopping at a bound makes a tick finish; nothing is lost, because the next
     * tick seeds from the checkpoint and carries on where this one stopped.
     *
     * A week of a minute task fits, so an ordinary outage drains in one tick.
     */
    private const MAX_MISSED_RUNS_PER_TICK = 10_000;

    private ?TriggerHeap $heap = null;
    private int $heapRevision = -1;
    private readonly Checkpoint $checkpoint;
    /** @var array<string, int> Task id to insertion index, ordering the runs of a shared due time. */
    private array $indices = [];

    public function __construct(
        private readonly Schedule $schedule,
        private readonly ClockInterface $clock,
    ) {
        $this->checkpoint = new Checkpoint($schedule->getName(), $schedule->getState(), $schedule->getLock());
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
            /** @var array<string, int> $walked How far this tick has gone into each backlog. */
            $walked = [];
            // Read here rather than in the constructor: a scheduler builds its generators when
            // it is built, and a task added to the schedule afterwards is still emitted by this
            // tick. An occurrence admitted under a revision that does not describe its own task
            // would send recovery back to the wrong configuration.
            $revision = self::revisionOf($this->schedule->tasks());

            /** @var array{DateTimeImmutable, int, RecurringTask, bool} $entry */
            while (!$heap->isEmpty() && $heap->top()[0] <= $now) {
                $entry = $heap->extract();
                [$time, $index, $task, $advance] = $entry;

                $yield = true;
                $nextTime = $task->getTrigger()->getNextRunDate($time);
                $walked[$task->getId()] = ($walked[$task->getId()] ?? 0) + 1;

                if (null !== $nextTime) {
                    if ($nextTime <= $time) {
                        throw new LogicException('A trigger must return a date strictly after the given one.');
                    }

                    if ($advance && $walked[$task->getId()] < self::MAX_MISSED_RUNS_PER_TICK) {
                        $heap->insert([$nextTime, $index, $task, true]);
                    }

                    if ($nextTime <= $now && $this->schedule->shouldProcessOnlyLastMissedRun()) {
                        // A newer missed run exists; only the latest one fires.
                        $yield = false;
                    }
                } elseif (!$advance && !$heap->isEmpty() && $heap->top()[0] <= $now && $heap->top()[2] === $task && $this->schedule->shouldProcessOnlyLastMissedRun()) {
                    $yield = false;
                }

                if (!$yield) {
                    // Not admitted: a missed run the schedule is configured to skip is never
                    // executed, so there is no execution to keep a record of.
                    $this->checkpoint->write($task->getId(), $time, TaskStatus::Skipped);
                    continue;
                }

                // Before the occurrence is emitted, and so before the checkpoint below moves
                // past it: nothing may acknowledge scheduling progress past an occurrence whose
                // record is not durable yet. A ledger that cannot write throws, the checkpoint
                // is not reached, and the occurrence stays due for the next process.
                $this->admit($task->getId(), $time, $now, $revision);

                $context = new TaskContext($this->schedule->getName(), $task->getId(), $task->getTrigger(), $time, $nextTime);

                // Taken rather than due for anyone reading the schedule while this run is in
                // flight. resolve() says what became of it, release() gives it back.
                //
                // Stamped as the claim is made, not as the tick began: the runs of one tick are
                // handed out one after another, and the ones behind a slow task would otherwise
                // start out with their reclaim window already part spent.
                $this->checkpoint->write($task->getId(), $time, TaskStatus::Claimed, at: $this->clock->now());

                yield $context => $task;
            }

            // Reached only by a tick that drained the heap, so a process that died partway
            // leaves the runs it never started due. A task left at the bound above drained it
            // too, in the sense that matters here: it put nothing back, and what it did not
            // reach is still behind its own checkpoint for the next tick to find.
            $this->checkpoint->markTick($now);
        } finally {
            $this->checkpoint->release();
        }
    }

    /**
     * The soonest pending run of the schedule, if any.
     *
     * The daemon loop sleeps until this date instead of polling on a fixed interval. When the
     * schedule lock is held by another process, null is returned: that process is the one
     * driving the schedule, so there is nothing here to wake up for.
     */
    public function getNextRunDate(): ?DateTimeImmutable
    {
        if (!$this->checkpoint->acquire($this->taskIds())) {
            return null;
        }

        try {
            $heap = $this->heap();
            $next = $heap->isEmpty() ? null : $heap->top()[0];
            $reclaim = $this->checkpoint->nextReclaimDate($this->clock->now(), $this->schedule->getReclaimAfter());

            if (null === $reclaim) {
                return $next;
            }

            return null === $next || $reclaim < $next ? $reclaim : $next;
        } finally {
            $this->checkpoint->release();
        }
    }

    /** Records what became of a claimed run, once the consumer knows. */
    public function resolve(TaskContext $context, TaskStatus $status, ?int $childPid = null): void
    {
        $this->checkpoint->resolve($context->taskId, $context->triggeredAt, $status, $childPid);
    }

    /**
     * Restarts the reclaim window of a claimed run at the moment the run actually begins, for a
     * consumer that starts a run later than it took it.
     *
     * @return bool Whether the run is still this process's to start.
     */
    public function markStarted(TaskContext $context): bool
    {
        return $this->checkpoint->markStarted($context->taskId, $context->triggeredAt, $this->clock->now());
    }

    /**
     * Hands the claim of a forked run over to the child process that is executing it.
     */
    public function assignChildOwner(TaskContext $context, int $pid): void
    {
        $this->checkpoint->assignChildOwner($context->taskId, $context->triggeredAt, $pid);
    }

    /**
     * Gives a claimed run back, leaving it due. For a run known not to have been started at all,
     * as opposed to one whose outcome is merely unknown.
     */
    public function release(TaskContext $context): void
    {
        $this->checkpoint->giveBack($context->taskId, $context->triggeredAt);

        // The pending runs held here were worked out when this one had been handed out, and the
        // run after it is the only one of the task they still carry. Dropping them puts the run
        // just given back in front of this scheduler too, rather than only in front of the next
        // process to read the state.
        $this->heap = null;
    }

    /**
     * @return list<string>
     */
    private function taskIds(): array
    {
        if (!$this->schedule->isStateful()) {
            return [];
        }

        return array_map(static fn(RecurringTask $task): string => $task->getId(), $this->schedule->tasks());
    }

    /**
     * Writes the record of an occurrence about to be emitted, where there is a ledger to write
     * it to.
     *
     * Idempotent by identity, so a process that died between this and the checkpoint below it
     * admits the same occurrence again on the next pass and finds what it already wrote.
     */
    private function admit(
        string $taskId,
        DateTimeImmutable $occurrence,
        DateTimeImmutable $now,
        string $sourceRevision,
    ): void {
        $ledger = $this->schedule->getLedger();

        if (null === $ledger) {
            return;
        }

        $ledger->admit(
            new ExecutionIdentity($this->schedule->getName(), $taskId, $occurrence),
            $sourceRevision,
            $now,
            $now->add(new DateInterval(self::RECOVERY_WINDOW)),
        );
    }

    /**
     * What a schedule is, as one value: each task's identifier and what that identifier stands
     * for.
     *
     * The identifiers alone would not say it. One generated from the task describes it already,
     * but a task given an identifier of its own with {@see RecurringTask::withId()} keeps that
     * identifier when its trigger or its work changes, and a revision read from identifiers
     * would call the new configuration the old one.
     *
     * Each part is written with its length in front of it, so that no two different lists of
     * identifiers and definitions are written out the same way. Two tasks that describe
     * themselves alike are still alike here: this is as exact as
     * {@see RecurringTask::getDefinition()} and no more.
     *
     * @param list<RecurringTask> $tasks
     */
    private static function revisionOf(array $tasks): string
    {
        $described = array_map(
            static fn(RecurringTask $task): string => sprintf(
                '%d:%s:%d:%s',
                strlen($task->getId()),
                $task->getId(),
                strlen($task->getDefinition()),
                $task->getDefinition(),
            ),
            $tasks,
        );

        return hash('xxh3', implode(',', $described));
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

        if (null === $position) {
            // Nothing stored yet, so the runs about to be computed are the first this schedule
            // has ever had. Where they are computed from is written down before any of them is
            // emitted: a tick interrupted before it drains then leaves the runs it never started
            // behind a position, rather than behind nothing for the next process to seed from.
            $position = $now;
            $this->checkpoint->markInitialPosition($position);
        }

        foreach ($this->schedule->tasks() as $task) {
            $index = $this->indices[$task->getId()] ??= count($this->indices);
            $pendingRuns = $this->checkpoint->pendingRuns($task->getId(), $now, $reclaimAfter);

            foreach ($pendingRuns as $pendingTime) {
                $this->heap->insert([$pendingTime, $index, $task, false]);
            }

            // No record yet — newly added, or added while the schedule was down — means the
            // position of the schedule.
            $seedTime = $this->checkpoint->resumePoint($task->getId(), $now, $reclaimAfter) ?? $position;

            if ([] !== $pendingRuns) {
                $lastPending = end($pendingRuns);

                if ($lastPending > $seedTime) {
                    $seedTime = $lastPending;
                }
            }

            if (null !== ($nextTime = $task->getTrigger()->getNextRunDate($seedTime))) {
                $this->heap->insert([$nextTime, $index, $task, true]);
            }
        }

        return $this->heap;
    }
}
