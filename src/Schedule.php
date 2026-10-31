<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

use Psr\SimpleCache\CacheInterface;
use DateInterval;
use DateTimeImmutable;
use Yiisoft\Mutex\MutexInterface;
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
    private ?MutexInterface $mutex = null;
    private ?CacheInterface $state = null;
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
     * acquiring the mutex runs the tasks, the others skip the tick silently.
     */
    public function lock(MutexInterface $mutex): static
    {
        $this->mutex = $mutex;

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
     * Sets how long a claimed run is left to its process before the schedule takes it back. A
     * gone process of this host is noticed at once with ext-posix; this covers the rest.
     *
     * It has to outlast the whole of a run, from being claimed to being over — which includes
     * the wait for a free slot under `--max-processes`, not only the work itself. Taking a run
     * back while its process still means to make it runs the task twice.
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
    public function getMutex(): ?MutexInterface
    {
        return $this->mutex;
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
