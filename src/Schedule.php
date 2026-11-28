<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

use Psr\SimpleCache\CacheInterface;
use DateInterval;
use DateTimeImmutable;
use Yiisoft\Scheduler\Lock\LockInterface;
use Yiisoft\Scheduler\Lock\MutexLock;
use Throwable;
use Yiisoft\Scheduler\Ledger\LedgerCommandInterface;
use Yiisoft\Scheduler\Ledger\LedgerQueryInterface;

use function array_key_exists;
use function is_int;
use function max;
use function array_values;
use function sprintf;

/**
 * A named collection of recurring tasks plus the runtime options applying to them as a whole.
 */
final class Schedule
{
    /** @var array<string, RecurringTask> */
    private array $tasks = [];
    private ?LockInterface $lock = null;
    private ?CacheInterface $state = null;
    private ?LedgerCommandInterface $ledger = null;
    private ?LedgerQueryInterface $ledgerQueries = null;
    private ?Overlap $overlap = null;
    private ?int $capacity = null;
    private bool $onlyLastMissedRun = false;
    private ?DateInterval $reclaimAfter = null;
    /** @var list<callable(TaskContext, RecurringTask): void> */
    private array $beforeListeners = [];
    /** @var list<callable(TaskContext, RecurringTask, mixed): void> */
    private array $afterListeners = [];
    /** @var list<callable(TaskContext, RecurringTask, Throwable): void> */
    private array $failureListeners = [];

    public function __construct(
        private readonly string $name = 'default',
    ) {}

    /**
     * Adds a task to the schedule.
     */
    public function task(RecurringTask $task, RecurringTask ...$tasks): static
    {
        foreach ([$task, ...$tasks] as $item) {
            $id = $item->getId();

            if (array_key_exists($id, $this->tasks)) {
                throw new Exception\LogicException(sprintf(
                    'A task with id "%s" already exists in the schedule. Tasks sharing a trigger and a target need'
                    . ' identifiers of their own, see RecurringTask::withId().',
                    $id,
                ));
            }

            $this->tasks[$id] = $item;
        }

        return $this;
    }

    /**
     * @return list<RecurringTask>
     */
    public function tasks(): array
    {
        return array_values($this->tasks);
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Prevents overlapping runs of this schedule across processes; the first process
     * acquiring the lock runs the tasks, the others skip the tick silently.
     *
     * {@see MutexLock} wraps a yiisoft/mutex implementation; anything else that can be held by
     * one process at a time implements {@see LockInterface} itself.
     */
    public function lock(LockInterface $lock): static
    {
        $this->lock = $lock;

        return $this;
    }

    /**
     * Records the lifecycle of every occurrence this schedule admits.
     *
     * Separate from the checkpoint, which records how far the schedule got. With a ledger, no
     * occurrence is emitted before its record is written, so a process that dies between the
     * two leaves work the ledger knows about rather than work nothing remembers.
     *
     * The executions are read back through the same object where it answers
     * {@see LedgerQueryInterface}, and through the second one where the two are separate. A
     * ledger that can only be written to leaves {@see Admission} nothing to read, so overlap
     * and capacity are then decided from the runs this process started and no others.
     */
    public function ledger(LedgerCommandInterface $ledger, ?LedgerQueryInterface $queries = null): static
    {
        $this->ledger = $ledger;

        // The two halves are usually one object, and are two parameters because they need not
        // be: an application writing its executions through one service and reading them from
        // a replica gives both.
        $this->ledgerQueries = $queries ?? ($ledger instanceof LedgerQueryInterface ? $ledger : null);

        return $this;
    }

    /**
     * Sets what becomes of a run of a task that is due while an earlier one is still going,
     * for every task of this schedule that does not say for itself.
     *
     * Without a ledger the question is answered from the runs this process started, which is
     * all a schedule remembering nothing can answer from. With one it is answered from the
     * executions that durably hold a process, so a restart does not let a task overlap itself
     * and neither does a second planner.
     */
    public function overlap(Overlap $overlap): static
    {
        $this->overlap = $overlap;

        return $this;
    }

    /**
     * Sets how many executions of this schedule may hold a process at once.
     *
     * The capacity is of the source rather than of a task: it is the room the application has
     * for the work of this schedule, which the tasks share. A run due with no room left is
     * given back rather than recorded, so nothing moves past it and the next tick offers it
     * again.
     *
     * Counted from the ledger where there is one, so an execution holding a process survives
     * the planner that started it: capacity comes back when the execution gives the process up,
     * and not when a planner stops or a worker is seen to exit.
     *
     * @param int $capacity How many at once, at least one.
     */
    public function capacity(int $capacity): static
    {
        if ($capacity < 1) {
            throw new Exception\LogicException(
                sprintf('The capacity of a schedule is at least one execution, %d given.', $capacity),
            );
        }

        $this->capacity = $capacity;

        return $this;
    }

    /**
     * Persists the schedule checkpoint, so a restarted process catches up the runs it missed.
     */
    public function stateful(CacheInterface $cache): static
    {
        $this->state = $cache;

        return $this;
    }

    /**
     * Sets how long a claimed run is left to its process before the schedule takes it back.
     * Every claim is taken back once the window runs out, including one whose process is still
     * there; a gone process of this host is noticed before that with ext-posix.
     *
     * It has to outlast the work itself. The window starts when the run starts rather than when
     * it was claimed, so the wait for a free slot under `--max-processes` is not charged against
     * it. Taking a run back while its process still means to make it runs the task twice.
     *
     * @param DateInterval|int $after The window, as a date interval or a number of seconds.
     */
    public function reclaimAfter(DateInterval|int $after): static
    {
        if (is_int($after)) {
            $after = new DateInterval(sprintf('PT%dS', max(0, $after)));
        }

        // A window of nothing would make every claim abandoned the moment it is read back, which
        // is the double run it is there to prevent.
        $epoch = new DateTimeImmutable('@0');

        if ($epoch->add($after) <= $epoch) {
            throw new Exception\LogicException('The reclaim window must be greater than zero.');
        }

        $this->reclaimAfter = $after;

        return $this;
    }

    public function getReclaimAfter(): DateInterval
    {
        return $this->reclaimAfter ??= new DateInterval('P1D');
    }

    /**
     * Whether the schedule checkpoint is persisted.
     *
     * Only a stateful schedule can be driven by a command that exits between runs, such as a
     * `schedule:run` cron entry: without a checkpoint a new process cannot tell what already ran.
     */
    public function isStateful(): bool
    {
        return null !== $this->state;
    }

    /**
     * When the process was down and several runs were missed, runs only the latest one.
     */
    public function processOnlyLastMissedRun(bool $onlyLast = true): static
    {
        $this->onlyLastMissedRun = $onlyLast;

        return $this;
    }

    /**
     * @param callable(TaskContext, RecurringTask): void $listener
     */
    public function before(callable $listener): static
    {
        $this->beforeListeners[] = $listener;

        return $this;
    }

    /**
     * @param callable(TaskContext, RecurringTask, mixed): void $listener
     */
    public function after(callable $listener): static
    {
        $this->afterListeners[] = $listener;

        return $this;
    }

    /**
     * @param callable(TaskContext, RecurringTask, Throwable): void $listener
     */
    public function onFailure(callable $listener): static
    {
        $this->failureListeners[] = $listener;

        return $this;
    }

    /**
     * @internal
     */
    public function getLock(): ?LockInterface
    {
        return $this->lock;
    }

    /**
     * @internal
     */
    public function getLedger(): ?LedgerCommandInterface
    {
        return $this->ledger;
    }

    /**
     * The ledger to read the executions of this schedule from, where it can be read.
     */
    public function getLedgerQueries(): ?LedgerQueryInterface
    {
        return $this->ledgerQueries;
    }

    /**
     * What this task says about overlapping itself, or what the schedule says for it.
     *
     * Skip is the answer where neither says, which is what a schedule has always done.
     */
    public function overlapOf(RecurringTask $task): Overlap
    {
        return $task->getOverlap() ?? $this->overlap ?? Overlap::Skip;
    }

    /**
     * How many executions of this schedule may hold a process at once, or null for no limit of
     * its own.
     */
    public function getCapacity(): ?int
    {
        return $this->capacity;
    }

    /**
     * @internal
     */
    public function getState(): ?CacheInterface
    {
        return $this->state;
    }

    /**
     * @internal
     */
    public function shouldProcessOnlyLastMissedRun(): bool
    {
        return $this->onlyLastMissedRun;
    }

    /**
     * @internal
     *
     * @return list<callable(TaskContext, RecurringTask): void>
     */
    public function getBeforeListeners(): array
    {
        return $this->beforeListeners;
    }

    /**
     * @internal
     *
     * @return list<callable(TaskContext, RecurringTask, mixed): void>
     */
    public function getAfterListeners(): array
    {
        return $this->afterListeners;
    }

    /**
     * @internal
     *
     * @return list<callable(TaskContext, RecurringTask, Throwable): void>
     */
    public function getFailureListeners(): array
    {
        return $this->failureListeners;
    }
}
