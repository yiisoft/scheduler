<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Generator;

use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use Yiisoft\Mutex\MutexInterface;
use Yiisoft\Scheduler\Exception\RuntimeException;

use function array_keys;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * The persisted-or-not position of a schedule: which runs were already emitted.
 *
 * The position is a point in time together with the tasks whose run at that very time was
 * emitted; a process resuming from a checkpoint re-discovers every missed run and skips the
 * ones already emitted.
 *
 * The tasks are held by their identifier rather than by their place in the schedule, which a
 * deploy adding or removing a task moves while the recorded runs stay as they were.
 *
 * @internal
 */
final class Checkpoint
{
    private const CACHE_KEY_PREFIX = 'yii_schedule_checkpoint_';

    private ?DateTimeImmutable $time = null;
    /** @var array<string, true> Identifiers of the tasks emitted at {@see $time}. */
    private array $emitted = [];
    private bool $locked = false;
    private int $revision = 0;

    public function __construct(
        private readonly string $scheduleName,
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

    /**
     * Releases the mutex, if held. The checkpoint itself is persisted on save().
     */
    public function release(): void
    {
        if ($this->locked) {
            $this->mutex?->release();
            $this->locked = false;
        }
    }

    public function time(): ?DateTimeImmutable
    {
        return $this->time;
    }

    /**
     * Whether the run of the given task at the checkpoint time was already emitted.
     */
    public function wasEmitted(string $taskId): bool
    {
        return isset($this->emitted[$taskId]);
    }

    /**
     * Whether any run was emitted at the checkpoint time, as opposed to the time being that of
     * an idle tick with nothing due.
     */
    public function hasEmitted(): bool
    {
        return [] !== $this->emitted;
    }

    /**
     * Marks the run of a task at the given time as emitted.
     *
     * The position never moves backwards: runs re-discovered and skipped during a catch-up are
     * reported here too, and an earlier time would make the runs after it look unemitted.
     */
    public function save(DateTimeImmutable $time, string $taskId): void
    {
        if (null !== $this->time && $time < $this->time) {
            return;
        }

        if (null === $this->time || $time > $this->time) {
            // Every run of the time before this one is emitted, so the set starts over.
            $this->time = $time;
            $this->emitted = [];
        } elseif (isset($this->emitted[$taskId])) {
            return;
        }

        $this->emitted[$taskId] = true;

        $this->persist();
    }

    /**
     * Records that the schedule was evaluated at the given time without emitting anything.
     *
     * The next process then knows the window (lastTick, now] is still to be covered: without
     * this, a single-tick-per-minute cron setup would never see any run as due.
     */
    public function markTick(DateTimeImmutable $now): void
    {
        // Never move the position backwards: an idle tick after emitted runs must keep them.
        if (null !== $this->time && $now <= $this->time) {
            return;
        }

        $this->time = $now;
        $this->emitted = [];

        $this->persist();
    }

    private function load(): void
    {
        if (null === $this->cache) {
            return;
        }

        /** @var mixed $state */
        $state = $this->cache->get($this->cacheKey());

        if (null === $state) {
            return;
        }

        if (!is_array($state) || !isset($state['time']) || !is_string($state['time'])) {
            throw new RuntimeException($this->unexpectedPayload());
        }

        $time = DateTimeImmutable::createFromFormat('U.u', $state['time']) ?: null;
        $emitted = self::readEmitted($state, $this->scheduleName);

        if ($emitted == $this->emitted && $time == $this->time) {
            return;
        }

        $this->time = $time;
        $this->emitted = $emitted;

        ++$this->revision;
    }

    /**
     * The tasks a stored payload says were emitted at its time.
     *
     * @param array<array-key, mixed> $state
     *
     * @return array<string, true>
     */
    private static function readEmitted(array $state, string $scheduleName): array
    {
        // A checkpoint written before the emitted runs were recorded by task id holds the place
        // of the last one in the schedule instead. Its time is kept and its runs are all left as
        // emitted, so an upgrade replays none of them and resumes strictly after it. This can go
        // once 1.0.0 is out and no such checkpoint can be in a cache any more.
        if (!isset($state['tasks']) && is_int($state['index'] ?? null)) {
            return [];
        }

        if (!isset($state['tasks']) || !is_array($state['tasks'])) {
            throw new RuntimeException(self::unexpectedPayloadMessage($scheduleName));
        }

        $emitted = [];

        /** @var mixed $taskId */
        foreach ($state['tasks'] as $taskId) {
            if (!is_string($taskId)) {
                throw new RuntimeException(self::unexpectedPayloadMessage($scheduleName));
            }

            $emitted[$taskId] = true;
        }

        return $emitted;
    }

    private function unexpectedPayload(): string
    {
        return self::unexpectedPayloadMessage($this->scheduleName);
    }

    private static function unexpectedPayloadMessage(string $scheduleName): string
    {
        return sprintf('The checkpoint of schedule "%s" holds an unexpected payload.', $scheduleName);
    }

    private function persist(): void
    {
        if (null === $this->cache || null === $this->time) {
            return;
        }

        $state = ['time' => $this->time->format('U.u'), 'tasks' => array_keys($this->emitted)];

        if (!$this->cache->set($this->cacheKey(), $state)) {
            throw new RuntimeException(
                sprintf('Unable to persist the checkpoint of schedule "%s".', $this->scheduleName),
            );
        }
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX . $this->scheduleName;
    }
}
