<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

use Psr\SimpleCache\CacheInterface;
use DateInterval;
use DateTimeImmutable;
use Yiisoft\Scheduler\Lock\LockInterface;
use Yiisoft\Scheduler\Lock\MutexLock;
use Throwable;

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
    private MissedRuns $missedRuns = MissedRuns::All;
    private int $missedRunLimit = RecurringTask::DEFAULT_MISSED_RUN_LIMIT;
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
     * What becomes of the runs missed while nothing was there to run them, for every task that
     * has not answered for itself with {@see RecurringTask::withMissedRuns()}.
     *
     * @param int|null $limit How many missed runs of one task a tick replays, for
     * {@see MissedRuns::All} alone. Must be positive. Left out,
     * {@see RecurringTask::DEFAULT_MISSED_RUN_LIMIT} applies.
     */
    public function missedRuns(MissedRuns $missedRuns, ?int $limit = null): static
    {
        if (null !== $limit && $limit < 1) {
            throw new Exception\LogicException(
                'The number of missed runs to replay in one tick must be positive: a tick'
                . ' replaying none of them is MissedRuns::Skip, which says so.',
            );
        }

        if (null !== $limit && MissedRuns::All !== $missedRuns) {
            throw new Exception\LogicException(
                'Only MissedRuns::All replays missed runs, so only it is bounded by a limit.',
            );
        }

        $this->missedRuns = $missedRuns;
        $this->missedRunLimit = $limit ?? RecurringTask::DEFAULT_MISSED_RUN_LIMIT;

        return $this;
    }

    /**
     * When the process was down and several runs were missed, runs only the latest one.
     *
     * The same as `missedRuns(MissedRuns::Last)`, and `missedRuns(MissedRuns::All)` when told
     * not to.
     */
    public function processOnlyLastMissedRun(bool $onlyLast = true): static
    {
        return $this->missedRuns($onlyLast ? MissedRuns::Last : MissedRuns::All);
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
    public function getState(): ?CacheInterface
    {
        return $this->state;
    }

    /**
     * What becomes of the missed runs of the given task: its own answer where it has one, and
     * the schedule's where it has not.
     *
     * @internal
     *
     * @return array{MissedRuns, int} The answer and, for {@see MissedRuns::All}, how many runs
     * one tick replays.
     */
    public function missedRunsOf(RecurringTask $task): array
    {
        $missedRuns = $task->getMissedRuns();

        return null === $missedRuns
            ? [$this->missedRuns, $this->missedRunLimit]
            : [$missedRuns, $task->getMissedRunLimit()];
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
