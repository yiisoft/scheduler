<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Generator;

use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Mutex\MutexInterface;
use Yiisoft\Scheduler\Exception\RuntimeException;

use function is_array;
use function is_int;
use function sprintf;

/**
 * The persisted-or-not position of a schedule: which runs were already emitted.
 *
 * The (time, index) pair identifies the last emitted run; a process resuming from a
 * checkpoint re-discovers every missed run and skips the ones already emitted.
 *
 * @internal
 */
final class Checkpoint
{
    private const CACHE_KEY_PREFIX = 'yii_schedule_checkpoint_';

    private ?DateTimeImmutable $time = null;
    private int $index = -1;
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

        $this->load();

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

    public function index(): int
    {
        return $this->index;
    }

    /**
     * Marks a run as emitted.
     *
     * The position never moves backwards: runs re-discovered and skipped during a catch-up are
     * reported here too, and lowering the position would make the ones after them look unemitted.
     */
    public function save(DateTimeImmutable $time, int $index): void
    {
        if (null !== $this->time && ($time < $this->time || ($time == $this->time && $index <= $this->index))) {
            return;
        }

        $this->time = $time;
        $this->index = $index;

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
        // Never move the position backwards: an idle tick after emitted runs must keep their index.
        if (null !== $this->time && $now <= $this->time) {
            return;
        }

        $this->time = $now;
        $this->index = -1;

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

        if (!is_array($state) || !isset($state['time'], $state['index']) || !is_int($state['index'])) {
            throw new RuntimeException(
                sprintf('The checkpoint of schedule "%s" holds an unexpected payload.', $this->scheduleName),
            );
        }

        $time = DateTimeImmutable::createFromFormat('U.u', (string) $state['time']) ?: null;
        $index = $state['index'];

        if ($index === $this->index && $time == $this->time) {
            return;
        }

        $this->time = $time;
        $this->index = $index;

        ++$this->revision;
    }

    private function persist(): void
    {
        if (null === $this->cache || null === $this->time) {
            return;
        }

        if (!$this->cache->set($this->cacheKey(), ['time' => $this->time->format('U.u'), 'index' => $this->index])) {
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
