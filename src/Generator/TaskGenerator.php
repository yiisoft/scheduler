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
use function end;

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
    private readonly ScheduleOwnership $ownership;
    private bool $owned = false;
    private bool $fenced = false;
    /** @var array<string, int> Task id to insertion index, ordering the runs of a shared due time. */
    private array $indices = [];

    public function __construct(
        private readonly Schedule $schedule,
        private readonly ClockInterface $clock,
    ) {
        $this->checkpoint = new Checkpoint($schedule->getName(), $schedule->getState(), $schedule->getLock());
        $this->ownership = new ScheduleOwnership($schedule->getName(), $schedule->getState(), $schedule->getLock());
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

        if (!$this->own($now) || !$this->checkpoint->acquire($this->taskIds())) {
            return;
        }

        try {
            $heap = $this->heap();
            /** @var array<string, int> $walked How far this tick has gone into each backlog. */
            $walked = [];

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

                    if ($advance) {
                        if ($walked[$task->getId()] < self::MAX_MISSED_RUNS_PER_TICK) {
                            $heap->insert([$nextTime, $index, $task, true]);
                        } else {
                            // The task is left with a backlog, while the runs held here are the
                            // ones this tick emitted and nothing more. Only a write by another
                            // process moves the revision they are kept against, so a scheduler
                            // outliving its ticks would hold an empty heap over work nobody else
                            // is going to touch, and the rest of the replay would wait for a
                            // restart. Dropped as the bound is reached rather than once the tick
                            // is over, which an exception or a caller that stops reading never
                            // gets to. This tick carries on over the heap it already has.
                            $this->heap = null;
                        }
                    }

                    if ($nextTime <= $now && $this->schedule->shouldProcessOnlyLastMissedRun()) {
                        // A newer missed run exists; only the latest one fires.
                        $yield = false;
                    }
                } elseif (!$advance && !$heap->isEmpty() && $heap->top()[0] <= $now && $heap->top()[2] === $task && $this->schedule->shouldProcessOnlyLastMissedRun()) {
                    $yield = false;
                }

                if (!$yield) {
                    $this->checkpoint->write($task->getId(), $time, TaskStatus::Skipped);
                    continue;
                }

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
        if (!$this->own($this->clock->now()) || !$this->checkpoint->acquire($this->taskIds())) {
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
     * Hands the schedule back for the next process to plan. Called once this one is done with
     * it, after whatever it has recorded of the runs it handed out.
     */
    public function disown(): void
    {
        $this->ownership->release();
        $this->owned = false;
    }

    /**
     * Whether the schedule has changed under this process since it took it on. Nothing more is
     * planned once it has: what this process worked out came from a schedule the records no
     * longer belong to.
     */
    public function isFenced(): bool
    {
        return $this->fenced;
    }

    /**
     * Whether this schedule is this process's to plan, taking it on where it is free and
     * saying so where it is still held.
     *
     * Asked before anything is planned, admitted or taken back, so that a second process
     * started against one schedule plans nothing rather than every other tick of it.
     */
    private function own(DateTimeImmutable $now): bool
    {
        if ($this->fenced) {
            return false;
        }

        $revision = ScheduleOwnership::revisionOf($this->scheduleRevisionIds());
        $replaceAfter = $this->schedule->getReplaceOwnerAfter();

        $status = $this->owned
            ? $this->ownership->keep($revision, $now, $replaceAfter)
            : $this->ownership->acquire($revision, $now, $replaceAfter);

        $this->owned = OwnershipStatus::Held === $status;
        $this->fenced = OwnershipStatus::Fenced === $status;

        return $this->owned;
    }

    /**
     * The tasks the schedule has, in the order they were added. What the owner took the
     * schedule on with, which is not the per-tick revision the checkpoint counts.
     *
     * @return list<string>
     */
    private function scheduleRevisionIds(): array
    {
        return array_map(static fn(RecurringTask $task): string => $task->getId(), $this->schedule->tasks());
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
