<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Generator;

use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use Yiisoft\Mutex\MutexInterface;
use Yiisoft\Scheduler\Exception\RuntimeException;

use function array_keys;
use function hash;
use function is_array;
use function is_string;
use function sprintf;

/**
 * The persisted-or-not position of a schedule: which runs were already emitted.
 *
 * Each task keeps the time of its own last emitted run, and the schedule keeps the time it was
 * last evaluated to the end. A task resumes after its own record; one that has none — newly
 * added, or added while the schedule was down — resumes from the position of the schedule.
 *
 * A record per task keeps a stored value the same size however many tasks share a due time, and
 * a run one write instead of a write of everything emitted so far.
 *
 * @internal
 */
final class Checkpoint
{
    private const CACHE_KEY_PREFIX = 'yii_schedule_checkpoint_';

    private ?DateTimeImmutable $time = null;
    /** @var array<string, DateTimeImmutable> Task id to the time of its last emitted run. */
    private array $lastRuns = [];
    private bool $locked = false;
    private int $revision = 0;

    /**
     * @param list<string> $taskIds Ids of the tasks of the schedule, whose records are read back.
     */
    public function __construct(
        private readonly string $scheduleName,
        private readonly array $taskIds = [],
        private readonly ?CacheInterface $cache = null,
        private readonly ?MutexInterface $mutex = null,
    ) {
        $this->load();
    }

    /**
     * Acquires the schedule mutex, if any. Returns false when another process holds it.
     *
     * The persisted position is re-read on every acquisition: another process may have advanced
     * it while this one was standing by, and replaying its runs would run them twice.
     */
    public function acquire(): bool
    {
        if (null !== $this->mutex && !$this->mutex->acquire(0)) {
            return false;
        }

        $this->locked = null !== $this->mutex;

        // The caller only releases what it was told it acquired, so a position that cannot be
        // read must not leave the mutex behind: nobody else could run the schedule until this
        // process is gone.
        try {
            $this->load();
        } catch (Throwable $error) {
            $this->release();

            throw $error;
        }

        return true;
    }

    /**
     * Changes whenever the position is replaced by one read from the shared state, so a caller
     * caching work derived from the position knows to recompute it.
     */
    public function revision(): int
    {
        return $this->revision;
    }

    /** Releases the mutex, if held. The checkpoint itself is persisted as it is recorded. */
    public function release(): void
    {
        if ($this->locked) {
            $this->mutex?->release();
            $this->locked = false;
        }
    }

    /** The time the schedule was last evaluated to the end: the seed of a task with no record. */
    public function time(): ?DateTimeImmutable
    {
        return $this->time;
    }

    public function lastRun(string $taskId): ?DateTimeImmutable
    {
        return $this->lastRuns[$taskId] ?? null;
    }

    /**
     * Records the run as emitted. Never moves backwards: a catch-up reports the runs it skips
     * here too, and an earlier time would make the ones after them look unemitted.
     */
    public function save(string $taskId, DateTimeImmutable $time): void
    {
        $recorded = $this->lastRuns[$taskId] ?? null;

        if (null !== $recorded && $time <= $recorded) {
            return;
        }

        $this->lastRuns[$taskId] = $time;

        if (null === $this->cache) {
            return;
        }

        if (!$this->cache->set($this->taskKey($taskId), $time->format('U.u'))) {
            throw new RuntimeException(sprintf(
                'Unable to persist the checkpoint of task "%s" of schedule "%s".',
                $taskId,
                $this->scheduleName,
            ));
        }
    }

    /**
     * Records that the schedule was evaluated to the end: every run due at or before this time is
     * emitted, so a task with no record of its own resumes from here. Only an uninterrupted tick
     * reaches it, which is what leaves the runs of a process that died partway still due.
     */
    public function markTick(DateTimeImmutable $now): void
    {
        // A process re-reading an older state must not drag the position back over runs emitted
        // in between.
        if (null !== $this->time && $now <= $this->time) {
            return;
        }

        $this->time = $now;

        if (null === $this->cache) {
            return;
        }

        if (!$this->cache->set($this->cacheKey(), ['time' => $now->format('U.u')])) {
            throw new RuntimeException(
                sprintf('Unable to persist the checkpoint of schedule "%s".', $this->scheduleName),
            );
        }
    }

    private function load(): void
    {
        if (null === $this->cache) {
            return;
        }

        $taskKeys = [];

        foreach ($this->taskIds as $taskId) {
            $taskKeys[$this->taskKey($taskId)] = $taskId;
        }

        $values = [];

        /** @var mixed $value */
        foreach ($this->cache->getMultiple([$this->cacheKey(), ...array_keys($taskKeys)]) as $key => $value) {
            $values[$key] = $value;
        }

        $time = $this->readPosition($values[$this->cacheKey()] ?? null);
        $lastRuns = [];

        foreach ($taskKeys as $key => $taskId) {
            /** @var mixed $stored */
            $stored = $values[$key] ?? null;

            if (null !== $stored) {
                $lastRuns[$taskId] = $this->readRunTime($stored, $taskId);
            }
        }

        if ($time == $this->time && $lastRuns == $this->lastRuns) {
            return;
        }

        $this->time = $time;
        $this->lastRuns = $lastRuns;

        ++$this->revision;
    }

    private function readPosition(mixed $state): ?DateTimeImmutable
    {
        if (null === $state) {
            return null;
        }

        // Either earlier format carries what was emitted at its time alongside it; only the time
        // is read, so an upgrade resumes after those runs without replaying them.
        if (!is_array($state) || !isset($state['time']) || !is_string($state['time'])) {
            throw new RuntimeException(
                sprintf('The checkpoint of schedule "%s" holds an unexpected payload.', $this->scheduleName),
            );
        }

        return DateTimeImmutable::createFromFormat('U.u', $state['time']) ?: null;
    }

    private function readRunTime(mixed $stored, string $taskId): DateTimeImmutable
    {
        $time = is_string($stored) ? DateTimeImmutable::createFromFormat('U.u', $stored) : false;

        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException(sprintf(
                'The checkpoint of task "%s" of schedule "%s" holds an unexpected payload.',
                $taskId,
                $this->scheduleName,
            ));
        }

        return $time;
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX . $this->scheduleName;
    }

    /** The id is hashed because withId() takes any string, while PSR-16 reserves `{}()/\@:`. */
    private function taskKey(string $taskId): string
    {
        return self::CACHE_KEY_PREFIX . $this->scheduleName . '_' . hash('xxh3', $taskId);
    }
}
