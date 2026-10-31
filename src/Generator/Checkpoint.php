<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Generator;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use Yiisoft\Mutex\MutexInterface;
use Yiisoft\Scheduler\Exception\RuntimeException;
use Yiisoft\Scheduler\TaskStatus;

use function array_keys;
use function array_pad;
use function explode;
use function function_exists;
use function gethostname;
use function getmypid;
use function hash;
use function is_array;
use function is_string;
use function posix_get_last_error;
use function posix_kill;
use function sprintf;

/**
 * The persisted-or-not position of a schedule: which runs were already emitted.
 *
 * Each task keeps the time of its own last emitted run and what became of it; the schedule keeps
 * the time it was last evaluated to the end, which is where a task with no record starts. One
 * record per task keeps a stored value the same size however many tasks share a due time.
 *
 * A run is claimed as it is handed out and resolved once its outcome is known, so another process
 * reads a run in flight as taken and one that was never started can be given back.
 *
 * A claim carries the process that made it. One whose process is gone is handed out again; a
 * process of another host, or of a host without ext-posix, cannot be asked about, so there the
 * claim stands until the reclaim window runs out — handing out a run that is still going would
 * run the task twice over.
 *
 * @internal
 *
 * @psalm-type Record = array{DateTimeImmutable, TaskStatus, string|null, DateTimeImmutable|null}
 */
final class Checkpoint
{
    private const CACHE_KEY_PREFIX = 'yii_schedule_checkpoint_';

    /** The errno of kill(2) for a process that exists but is not ours to signal. */
    private const EPERM = 1;

    private ?DateTimeImmutable $time = null;
    /** @var array<string, Record> Run, outcome, and the owner and moment of a claim. */
    private array $records = [];
    private bool $locked = false;
    private int $revision = 0;

    /** @var list<string> Ids of the tasks whose records are read back. */
    private array $taskIds = [];

    public function __construct(
        private readonly string $scheduleName,
        private readonly ?CacheInterface $cache = null,
        private readonly ?MutexInterface $mutex = null,
    ) {}

    /**
     * Acquires the schedule mutex, if any. Returns false when another process holds it. The state
     * is re-read each time: another process may have advanced it while this one stood by.
     */
    /**
     * @param list<string> $taskIds Ids of the tasks of the schedule as it stands now, so a task
     *                              added after the scheduler was built has its record read back.
     */
    public function acquire(array $taskIds): bool
    {
        if (null !== $this->mutex && !$this->mutex->acquire(0)) {
            return false;
        }

        $this->taskIds = $taskIds;

        $this->locked = null !== $this->mutex;

        // The caller only releases what it was told it acquired, so state that cannot be read
        // must not leave the mutex behind.
        try {
            $this->load();
        } catch (Throwable $error) {
            $this->release();

            throw $error;
        }

        return true;
    }

    /** Changes when the state is replaced by one read back, so cached work can be recomputed. */
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

    /** The time the schedule was last evaluated to the end: where a task with no record starts. */
    public function time(): ?DateTimeImmutable
    {
        return $this->time;
    }

    /**
     * @psalm-return Record|null
     *
     * @return array{DateTimeImmutable, TaskStatus, string|null, DateTimeImmutable|null}|null
     */
    public function record(string $taskId): ?array
    {
        return $this->records[$taskId] ?? null;
    }

    /**
     * Writes the record of a task. Claiming, resolving and giving back all come through here, and
     * each is the one writer of that record, so none has to guard against the others.
     */
    public function write(
        string $taskId,
        DateTimeImmutable $time,
        TaskStatus $status,
        ?string $owner = null,
        ?DateTimeImmutable $at = null,
    ): void {
        $claimed = TaskStatus::Claimed === $status;
        $owner = $claimed ? $owner ?? self::owner() : null;
        $at = $claimed ? $at ?? $time : null;

        $this->records[$taskId] = [$time, $status, $owner, $at];

        if (null === $this->cache) {
            return;
        }

        $state = [
            'time' => $time->format('U.u'),
            'status' => $status->value,
            'owner' => $owner,
            'at' => $at?->format('U.u'),
        ];

        if (!$this->cache->set($this->taskKey($taskId), $state)) {
            throw new RuntimeException(sprintf(
                'Unable to persist the checkpoint of task "%s" of schedule "%s".',
                $taskId,
                $this->scheduleName,
            ));
        }
    }

    /**
     * Records that the schedule was evaluated to the end, which is what lets a cron setup see the
     * window (lastTick, now] as still to cover. Only an uninterrupted tick reaches it.
     */
    public function markTick(DateTimeImmutable $now): void
    {
        // A process re-reading an older state must not drag the position back.
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

    /**
     * Where the task resumes: after its recorded run, or just before it when that run is to be
     * handed out again — given back unstarted, or claimed by a process nobody is left to hear from.
     */
    public function resumePoint(string $taskId, DateTimeImmutable $now, DateInterval $reclaimAfter): ?DateTimeImmutable
    {
        $record = $this->records[$taskId] ?? null;

        if (null === $record) {
            return null;
        }

        [$time, $status] = $record;

        if (TaskStatus::Released === $status || self::isReclaimable($record, $now, $reclaimAfter)) {
            return $time->modify('-1 microsecond');
        }

        return $time;
    }

    /**
     * Whether any record is a claim there is no longer any point in waiting on. A heap built while
     * a claim held has to be rebuilt once that stops being true, or the run it stands for is never
     * handed out by a scheduler that keeps running.
     */
    public function hasReclaimableClaim(DateTimeImmutable $now, DateInterval $reclaimAfter): bool
    {
        foreach ($this->records as $record) {
            if (self::isReclaimable($record, $now, $reclaimAfter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Records what became of a claimed run. A resolution of an earlier run than the one on record
     * is dropped: the runs of a task are claimed in order but resolved as their outcomes arrive,
     * and moving the record back would hand out the ones in between a second time.
     */
    public function resolve(string $taskId, DateTimeImmutable $time, TaskStatus $status): void
    {
        $record = $this->records[$taskId] ?? null;

        if (null !== $record && $time < $record[0]) {
            return;
        }

        $this->write($taskId, $time, $status);
    }

    /**
     * Hands a claimed run back, leaving it due. A later run than the one on record is dropped the
     * same way, so giving back several leaves the task resuming before the earliest of them.
     */
    public function giveBack(string $taskId, DateTimeImmutable $time): void
    {
        $record = $this->records[$taskId] ?? null;

        if (null !== $record && $time > $record[0]) {
            return;
        }

        $this->write($taskId, $time, TaskStatus::Released);
    }

    /**
     * @psalm-param Record $record
     */
    private static function isReclaimable(array $record, DateTimeImmutable $now, DateInterval $reclaimAfter): bool
    {
        [, $status, $owner, $at] = $record;

        if (TaskStatus::Claimed !== $status) {
            return false;
        }

        return (null !== $owner && self::isGone($owner))
            || (null !== $at && $at->add($reclaimAfter) <= $now);
    }

    /** The process a claim is made by, as "host/pid". */
    private static function owner(): string
    {
        return self::host() . '/' . (getmypid() ?: 0);
    }

    /**
     * Only a process of this host can be asked about, and only with ext-posix. Anything else
     * reads as still there: a run handed out twice is worse than one picked up late.
     */
    private static function host(): string
    {
        return gethostname() ?: 'unknown';
    }

    private static function isGone(string $owner): bool
    {
        [$host, $pid] = array_pad(explode('/', $owner, 2), 2, '');

        // A payload holding anything but an exact pid reads as a process still there.
        if ($host !== self::host() || !function_exists('posix_kill') || (string) (int) $pid !== $pid) {
            return false;
        }

        if ((int) $pid <= 0) {
            return false;
        }

        if (posix_kill((int) $pid, 0)) {
            return false;
        }

        // The signal is refused for a process of another user, which is still a process.
        return self::EPERM !== posix_get_last_error();
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
        $records = [];

        foreach ($taskKeys as $key => $taskId) {
            /** @var mixed $stored */
            $stored = $values[$key] ?? null;

            if (null !== $stored) {
                $records[$taskId] = $this->readRecord($stored, $taskId);
            }
        }

        if ($time == $this->time && $records == $this->records) {
            return;
        }

        $this->time = $time;
        $this->records = $records;

        ++$this->revision;
    }

    private function readPosition(mixed $state): ?DateTimeImmutable
    {
        if (null === $state) {
            return null;
        }

        // A position written before the runs were recorded per task carries the place, or the
        // ids, of the runs emitted at its time alongside it. Only the time is read, so those
        // runs are left as emitted and an upgrade resumes after them without replaying any.
        if (!is_array($state) || !isset($state['time']) || !is_string($state['time'])) {
            throw new RuntimeException(
                sprintf('The checkpoint of schedule "%s" holds an unexpected payload.', $this->scheduleName),
            );
        }

        return DateTimeImmutable::createFromFormat('U.u', $state['time']) ?: null;
    }

    /**
     * @psalm-return Record
     *
     * @return array{DateTimeImmutable, TaskStatus, string|null, DateTimeImmutable|null}
     */
    private function readRecord(mixed $stored, string $taskId): array
    {
        // A record written before a run carried what became of it holds the time on its own.
        // Such a run is over either way, so it reads as done and the task resumes after it.
        $status = TaskStatus::Done;
        $owner = null;
        $at = null;

        if (is_array($stored)) {
            if (!isset($stored['time'], $stored['status']) || !is_string($stored['status'])) {
                throw new RuntimeException($this->unexpectedTaskPayload($taskId));
            }

            $status = TaskStatus::tryFrom($stored['status']);

            if (null === $status) {
                throw new RuntimeException($this->unexpectedTaskPayload($taskId));
            }

            /** @var mixed $holder */
            $holder = $stored['owner'] ?? null;

            if (null !== $holder && !is_string($holder)) {
                throw new RuntimeException($this->unexpectedTaskPayload($taskId));
            }

            /** @var mixed $claimedAt */
            $claimedAt = $stored['at'] ?? null;

            if (null !== $claimedAt) {
                $at = is_string($claimedAt) ? DateTimeImmutable::createFromFormat('U.u', $claimedAt) : false;

                if (!$at instanceof DateTimeImmutable) {
                    throw new RuntimeException($this->unexpectedTaskPayload($taskId));
                }
            }

            $owner = $holder;
            /** @var mixed $stored */
            $stored = $stored['time'];
        }

        $time = is_string($stored) ? DateTimeImmutable::createFromFormat('U.u', $stored) : false;

        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException($this->unexpectedTaskPayload($taskId));
        }

        return [$time, $status, $owner, $at];
    }

    private function unexpectedTaskPayload(string $taskId): string
    {
        return sprintf(
            'The checkpoint of task "%s" of schedule "%s" holds an unexpected payload.',
            $taskId,
            $this->scheduleName,
        );
    }

    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX . $this->scheduleName;
    }

    /**
     * The key a task keeps its record under. The id is hashed because {@see
     * \Yiisoft\Scheduler\RecurringTask::withId()} takes any string, while PSR-16 reserves
     * `{}()/\@:` in a key.
     */
    private function taskKey(string $taskId): string
    {
        return self::CACHE_KEY_PREFIX . $this->scheduleName . '_' . hash('xxh3', $taskId);
    }
}
