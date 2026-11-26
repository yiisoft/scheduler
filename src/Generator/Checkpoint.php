<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Generator;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use Yiisoft\Scheduler\Lock\LockInterface;
use Yiisoft\Scheduler\Exception\LockTimeoutException;
use Yiisoft\Scheduler\Exception\RuntimeException;
use Yiisoft\Scheduler\TaskStatus;

use function array_key_last;
use function array_keys;
use function array_pad;
use function count;
use function explode;
use function function_exists;
use function gethostname;
use function getmypid;
use function hash;
use function is_array;
use function is_string;
use function ksort;
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
 * A claim carries the process that made it, and the run is handed out again once that process
 * is gone or the reclaim window runs out, whichever comes first. A process of another host, or
 * of a host without ext-posix, cannot be asked about, and a live one is no promise that the run
 * is still going either, since its pid may since have been given to something else. The window
 * is what bounds both, and asking about the process only brings it forward.
 *
 * @internal
 *
 * @psalm-type Claim = array{string, DateTimeImmutable}
 * @psalm-type Record = array{DateTimeImmutable, TaskStatus, string|null, DateTimeImmutable|null, array<string, Claim>}
 */
final class Checkpoint
{
    private const CACHE_KEY_PREFIX = 'yii_schedule_checkpoint_';

    /** The errno of kill(2) for a process that exists but is not ours to signal. */
    private const EPERM = 1;

    /**
     * How long to wait for the schedule lock before giving up on recording what became of a run.
     * Long enough to outlast another process's read and write, short enough that a scheduler is
     * not held forever by a lock whose holder is gone.
     */
    private const WRITE_LOCK_TIMEOUT_SECONDS = 10;

    private ?DateTimeImmutable $time = null;
    /** @var array<string, Record> Run, outcome, and the owner, moment and claims of a task. */
    private array $records = [];
    /** @var array<string, true> Tasks whose record still carries an unresolved claim. */
    private array $claimedTasks = [];
    private bool $locked = false;
    private int $revision = 0;

    /** Worked out once: the host is the same for the whole life of the process. */
    private static ?string $host = null;
    private static ?string $owner = null;
    /** The pid the owner in hand was made for, so a fork is told apart from its parent. */
    private static int|false|null $ownerPid = null;

    /** @var list<string> Ids of the tasks whose records are read back. */
    private array $taskIds = [];

    public function __construct(
        private readonly string $scheduleName,
        private readonly ?CacheInterface $cache = null,
        private readonly ?LockInterface $lock = null,
    ) {}

    /**
     * Acquires the schedule lock, if any. Returns false when another process holds it. The state
     * is re-read each time: another process may have advanced it while this one stood by.
     *
     * @param list<string> $taskIds Ids of the tasks of the schedule as it stands now, so a task
     *                              added after the scheduler was built has its record read back.
     */
    public function acquire(array $taskIds): bool
    {
        if (null !== $this->lock && !$this->lock->acquire(0)) {
            return false;
        }

        $this->taskIds = $taskIds;

        $this->locked = null !== $this->lock;

        // The caller only releases what it was told it acquired, so state that cannot be read
        // must not leave the lock behind.
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

    /** Releases the lock, if held. The checkpoint itself is persisted as it is recorded. */
    public function release(): void
    {
        if ($this->locked) {
            $this->lock?->release();
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
     * @return array{DateTimeImmutable, TaskStatus, string|null, DateTimeImmutable|null, array<string, array{string, DateTimeImmutable}>}|null
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
        $runKey = $time->format('U.u');

        $previous = $this->records[$taskId] ?? null;
        $claims = $previous[4] ?? [];

        if ($claimed && null !== $owner && null !== $at) {
            $claims[$runKey] = [$owner, $at];
            ksort($claims);
        } elseif (TaskStatus::Released === $status) {
            $claims[$runKey] = ['', $time];
            ksort($claims);
        } else {
            unset($claims[$runKey]);
        }

        // Resolving an earlier run of a catch-up must not move the task's position behind a
        // later run that has already been recorded.
        if (null !== $previous && $previous[0] > $time && !$claimed) {
            $time = $previous[0];

            if (isset($claims[$time->format('U.u')])) {
                [$claimOwner, $claimAt] = $claims[$time->format('U.u')];
                $status = '' === $claimOwner ? TaskStatus::Released : TaskStatus::Claimed;
                $owner = '' === $claimOwner ? null : $claimOwner;
                $at = '' === $claimOwner ? null : $claimAt;
            } else {
                $status = $previous[1];
                $owner = $previous[2];
                $at = $previous[3];
            }
        } elseif ([] !== $claims && !$claimed && TaskStatus::Released !== $status) {
            $lastClaimKey = array_key_last($claims);
            $lastClaimTime = DateTimeImmutable::createFromFormat('U.u', $lastClaimKey);

            if ($lastClaimTime instanceof DateTimeImmutable && $lastClaimTime >= $time) {
                [$claimOwner, $claimAt] = $claims[$lastClaimKey];
                $time = $lastClaimTime;
                $status = '' === $claimOwner ? TaskStatus::Released : TaskStatus::Claimed;
                $owner = '' === $claimOwner ? null : $claimOwner;
                $at = '' === $claimOwner ? null : $claimAt;
            }
        }

        $this->records[$taskId] = [$time, $status, $owner, $at, $claims];
        $this->trackClaimedTask($taskId);

        if (null === $this->cache) {
            return;
        }

        $state = [
            'time' => $time->format('U.u'),
            'status' => $status->value,
            'owner' => $owner,
            'at' => $at?->format('U.u'),
        ];

        if ($this->needsPersistedClaims($this->records[$taskId])) {
            $persistedClaims = [];

            foreach ($claims as $claimKey => [$claimOwner, $claimAt]) {
                $persistedClaims[$claimKey] = [
                    'owner' => '' === $claimOwner ? null : $claimOwner,
                    'at' => '' === $claimOwner ? null : $claimAt->format('U.u'),
                ];
            }

            $state['claims'] = $persistedClaims;
        }

        if (!$this->cache->set($this->taskKey($taskId), $state)) {
            throw new RuntimeException(sprintf(
                'Unable to persist the checkpoint of task "%s" of schedule "%s".',
                $taskId,
                $this->scheduleName,
            ));
        }
    }

    /**
     * Restarts the reclaim window of a claimed run at the moment the run actually begins.
     *
     * A run claimed as it is handed out may then wait for a free process slot. Charging that wait
     * against the window would hand the run out a second time while the process holding it is
     * still about to start it, so the window counts from here instead of from the claim.
     *
     * Anything but this process's own claim of this very run is left alone: the record has moved
     * on, and whoever moved it is the one that owns it now.
     *
     * @return bool Whether the run is still this process's to start. False means the wait for a
     * free slot outlasted the claim and another process has taken the run over, so starting it
     * here would run it twice.
     */
    public function markStarted(string $taskId, DateTimeImmutable $time, DateTimeImmutable $at): bool
    {
        return $this->exclusively(fn(): bool => $this->startUnderLock($taskId, $time, $at));
    }

    /**
     * Hands the claim of a forked run over to the child process that is executing it, so a child
     * that outlives or dies independently of its parent is tracked by its own pid.
     */
    public function assignChildOwner(string $taskId, DateTimeImmutable $time, int $pid): void
    {
        $this->exclusively(function () use ($taskId, $time, $pid): void {
            $this->assignChildOwnerUnderLock($taskId, $time, $pid);
        });
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

        $this->writePosition($now);
    }

    /**
     * Records the point a schedule with no position yet starts from, before any of its runs are
     * emitted.
     *
     * Until a tick drains, the position is the only thing telling a task with no record of its
     * own where to resume. Without this, a first tick that dies partway would leave the runs it
     * never started with nothing behind them at all, and the process replacing it would seed
     * those tasks from its own now and step over them.
     */
    public function markInitialPosition(DateTimeImmutable $now): void
    {
        if (null !== $this->time) {
            return;
        }

        $this->writePosition($now);
    }

    /**
     * Where the task resumes for new runs after everything recorded so far.
     */
    public function resumePoint(string $taskId, DateTimeImmutable $now, DateInterval $reclaimAfter): ?DateTimeImmutable
    {
        $record = $this->records[$taskId] ?? null;

        if (null === $record) {
            return null;
        }

        return $record[0];
    }

    /**
     * Runs of the task that were handed back unstarted or whose claim can now be taken back, in
     * chronological order, to be put directly back on the schedule before the next trigger date.
     *
     * @return list<DateTimeImmutable>
     */
    public function pendingRuns(string $taskId, DateTimeImmutable $now, DateInterval $reclaimAfter): array
    {
        $record = $this->records[$taskId] ?? null;

        if (null === $record) {
            return [];
        }

        [$time, $status, , , $claims] = $record;

        if ([] === $claims) {
            if (TaskStatus::Released === $status || self::isReclaimable($record, $now, $reclaimAfter)) {
                return [$time];
            }

            return [];
        }

        $pending = [];

        foreach ($claims as $runKey => [$owner, $at]) {
            if ('' === $owner || self::isClaimReclaimable($owner, $at, $now, $reclaimAfter)) {
                $runTime = DateTimeImmutable::createFromFormat('U.u', $runKey);

                if ($runTime instanceof DateTimeImmutable) {
                    $pending[] = $runTime;
                }
            }
        }

        return $pending;
    }

    /**
     * Whether any record is a claim there is no longer any point in waiting on. A heap built while
     * a claim held has to be rebuilt once that stops being true, or the run it stands for is never
     * handed out by a scheduler that keeps running.
     */
    public function hasReclaimableClaim(DateTimeImmutable $now, DateInterval $reclaimAfter): bool
    {
        foreach ($this->claimedTasks as $taskId => $_) {
            $record = $this->records[$taskId] ?? null;

            if (null !== $record && self::hasReclaimableInRecord($record, $now, $reclaimAfter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The earliest moment an active claim of the schedule can be taken back by the reclaim window,
     * so a sleeping daemon wakes up to reclaim it even when the task's next trigger run is later.
     */
    public function nextReclaimDate(DateTimeImmutable $now, DateInterval $reclaimAfter): ?DateTimeImmutable
    {
        $earliest = null;

        foreach ($this->claimedTasks as $taskId => $_) {
            $record = $this->records[$taskId] ?? null;

            if (null === $record) {
                continue;
            }

            foreach (self::claimDeadlines($record, $now, $reclaimAfter) as $deadline) {
                if (null === $earliest || $deadline < $earliest) {
                    $earliest = $deadline;
                }
            }
        }

        return $earliest;
    }

    /**
     * Records what became of a claimed run, if the state still holds this process's claim of it.
     *
     * An outcome arrives long after the claim was made, and by then the task may have been taken
     * over: the claim ran out of window, or the run after it was already handed out and finished
     * elsewhere. Writing the outcome anyway would move the record behind a run that is over, and
     * hand that run out again.
     */
    public function resolve(string $taskId, DateTimeImmutable $time, TaskStatus $status, ?int $childPid = null): void
    {
        $this->exclusively(function () use ($taskId, $time, $status, $childPid): void {
            $this->resolveUnderLock($taskId, $time, $status, $childPid);
        });
    }

    /**
     * Hands a claimed run back, leaving it due. A later run than the one on record is dropped the
     * same way, so giving back several leaves the task resuming before the earliest of them.
     */
    public function giveBack(string $taskId, DateTimeImmutable $time): void
    {
        $this->exclusively(function () use ($taskId, $time): void {
            $this->giveBackUnderLock($taskId, $time);
        });
    }

    /**
     * Carries out a read, a decision made on what it says, and the write that follows from it,
     * with nobody else writing the state in between.
     *
     * Reading the record back is not enough on its own: between that read and the write, another
     * scheduler can finish a later run of the same task, and the write then puts the record back
     * where that run started. The claim is made under the schedule lock, and so is what is
     * recorded about it afterwards.
     *
     * A tick holds the lock from the moment it starts generating, and a sequential run is
     * resolved inside that, so the lock is taken here only when this process is not already
     * holding it: a lock is not reentrant and taking it twice would wait on this process.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function exclusively(callable $operation): mixed
    {
        if ($this->locked || null === $this->lock) {
            return $operation();
        }

        // Waiting, unlike the tick, which moves on to let another process run the schedule: what
        // became of a run that already happened has to be recorded, and whoever holds the lock
        // holds it for a read and a write.
        if (!$this->lock->acquire(self::WRITE_LOCK_TIMEOUT_SECONDS)) {
            throw new LockTimeoutException(sprintf(
                'Unable to record the outcome of a run of schedule "%s": the schedule lock was held'
                . ' for longer than %d seconds.',
                $this->scheduleName,
                self::WRITE_LOCK_TIMEOUT_SECONDS,
            ));
        }

        $this->locked = true;

        try {
            return $operation();
        } finally {
            $this->locked = false;
            $this->lock->release();
        }
    }

    private function startUnderLock(string $taskId, DateTimeImmutable $time, DateTimeImmutable $at): bool
    {
        $this->reread($taskId);

        $record = $this->records[$taskId] ?? null;

        if (null === $record) {
            return false;
        }

        [$recordTime, $status, $owner, , $claims] = $record;
        $runKey = $time->format('U.u');

        if (isset($claims[$runKey])) {
            if (self::owner() !== $claims[$runKey][0]) {
                return false;
            }
        } elseif (TaskStatus::Claimed !== $status || self::owner() !== $owner || $time > $recordTime) {
            return false;
        }

        $this->write($taskId, $time, TaskStatus::Claimed, self::owner(), $at);

        return true;
    }

    private function assignChildOwnerUnderLock(string $taskId, DateTimeImmutable $time, int $pid): void
    {
        $this->reread($taskId);

        $record = $this->records[$taskId] ?? null;

        if (null === $record) {
            return;
        }

        [$recordTime, $status, $owner, $at, $claims] = $record;
        $runKey = $time->format('U.u');
        $childOwner = self::host() . '/' . $pid;

        if (isset($claims[$runKey])) {
            if (self::owner() !== $claims[$runKey][0]) {
                return;
            }

            $at = $claims[$runKey][1];
        } elseif (TaskStatus::Claimed !== $status || self::owner() !== $owner || $time != $recordTime) {
            return;
        }

        $this->write($taskId, $time, TaskStatus::Claimed, $childOwner, $at ?? $time);
    }

    private function resolveUnderLock(string $taskId, DateTimeImmutable $time, TaskStatus $status, ?int $childPid = null): void
    {
        $this->reread($taskId);

        $record = $this->records[$taskId] ?? null;

        if (null === $record) {
            return;
        }

        [$recordTime, $recordStatus, $owner, , $claims] = $record;
        $runKey = $time->format('U.u');

        if (isset($claims[$runKey])) {
            if (!$this->isOurOwner($claims[$runKey][0], $childPid)) {
                return;
            }

            $this->write($taskId, $time, $status);

            return;
        }

        if (TaskStatus::Claimed !== $recordStatus || !$this->isOurOwner($owner, $childPid) || $time != $recordTime) {
            return;
        }

        $this->write($taskId, $time, $status);
    }

    private function giveBackUnderLock(string $taskId, DateTimeImmutable $time): void
    {
        $this->reread($taskId);

        $record = $this->records[$taskId] ?? null;

        if (null === $record) {
            return;
        }

        [$recordTime, $recordStatus, $owner, , $claims] = $record;
        $runKey = $time->format('U.u');

        if (isset($claims[$runKey])) {
            if (self::owner() !== $claims[$runKey][0]) {
                return;
            }

            $this->write($taskId, $time, TaskStatus::Released);

            return;
        }

        if (TaskStatus::Claimed !== $recordStatus || self::owner() !== $owner || $time > $recordTime) {
            return;
        }

        $this->write($taskId, $time, TaskStatus::Released);
    }

    private function isOurOwner(?string $owner, ?int $childPid): bool
    {
        if (null === $owner) {
            return false;
        }

        if (self::owner() === $owner) {
            return true;
        }

        return null !== $childPid && (self::host() . '/' . $childPid) === $owner;
    }

    /** Replaces the record of one task with what the shared state holds for it now. */
    private function reread(string $taskId): void
    {
        if (null === $this->cache) {
            return;
        }

        /** @var mixed $stored */
        $stored = $this->cache->get($this->taskKey($taskId));

        $before = $this->records[$taskId] ?? null;

        if (null === $stored) {
            unset($this->records[$taskId]);
        } else {
            $this->records[$taskId] = $this->readRecord($stored, $taskId);
        }

        $this->trackClaimedTask($taskId);

        // The revision is what tells a generator its cached pending work is stale, and load()
        // can only see a change by comparing what it read against what is held. Rereading one
        // record has already replaced it, so the change would be invisible there: a generator
        // would keep a heap built from the record this call just replaced and emit a run that
        // another process has since recorded as done.
        if (($this->records[$taskId] ?? null) != $before) {
            ++$this->revision;
        }
    }

    private function writePosition(DateTimeImmutable $now): void
    {
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

    private static function isClaimReclaimable(
        string $owner,
        DateTimeImmutable $at,
        DateTimeImmutable $now,
        DateInterval $reclaimAfter,
    ): bool {
        return ('' !== $owner && self::isGone($owner))
            || $at->add($reclaimAfter) <= $now;
    }

    /**
     * @psalm-param Record $record
     */
    private static function hasReclaimableInRecord(
        array $record,
        DateTimeImmutable $now,
        DateInterval $reclaimAfter,
    ): bool {
        [, , , , $claims] = $record;

        if ([] === $claims) {
            return self::isReclaimable($record, $now, $reclaimAfter);
        }

        foreach ($claims as [$owner, $at]) {
            if ('' !== $owner && self::isClaimReclaimable($owner, $at, $now, $reclaimAfter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @psalm-param Record $record
     *
     * @return list<DateTimeImmutable>
     */
    private static function claimDeadlines(
        array $record,
        DateTimeImmutable $now,
        DateInterval $reclaimAfter,
    ): array {
        [, $status, $owner, $at, $claims] = $record;

        if ([] === $claims) {
            if (TaskStatus::Claimed !== $status || null === $at) {
                return [];
            }

            if (null !== $owner && self::isGone($owner)) {
                return [$now];
            }

            return [$at->add($reclaimAfter)];
        }

        $deadlines = [];

        foreach ($claims as [$claimOwner, $claimAt]) {
            if ('' === $claimOwner) {
                continue;
            }

            $deadlines[] = self::isGone($claimOwner) ? $now : $claimAt->add($reclaimAfter);
        }

        return $deadlines;
    }

    private function trackClaimedTask(string $taskId): void
    {
        $record = $this->records[$taskId] ?? null;

        if (null !== $record && self::recordHasActiveClaim($record)) {
            $this->claimedTasks[$taskId] = true;
        } else {
            unset($this->claimedTasks[$taskId]);
        }
    }

    /**
     * @psalm-param Record $record
     */
    private static function recordHasActiveClaim(array $record): bool
    {
        [, $status, , , $claims] = $record;

        if (TaskStatus::Claimed === $status) {
            return true;
        }

        foreach ($claims as [$owner]) {
            if ('' !== $owner) {
                return true;
            }
        }

        return false;
    }

    /**
     * @psalm-param Record $record
     */
    private function needsPersistedClaims(array $record): bool
    {
        [$time, $status, $owner, $at, $claims] = $record;

        if ([] === $claims) {
            return false;
        }

        if (count($claims) > 1) {
            return true;
        }

        $runKey = $time->format('U.u');

        if (!isset($claims[$runKey])) {
            return true;
        }

        [$claimOwner, $claimAt] = $claims[$runKey];

        if ('' === $claimOwner) {
            return TaskStatus::Released !== $status;
        }

        return TaskStatus::Claimed !== $status || $owner !== $claimOwner || $at != $claimAt;
    }

    /** The process a claim is made by, as "host/pid". */
    private static function owner(): string
    {
        $pid = getmypid();

        // The pid is read every time and the owner kept only while it stands. A fork copies
        // the memory of its parent, this string with it, so a worker forked from a process
        // that has claimed anything would otherwise claim as its parent: its claim would name
        // a pid that is still alive once the worker is gone, and the run would wait out the
        // reclaim window instead of being picked up as soon as the worker is collected.
        if (null !== self::$owner && $pid === self::$ownerPid) {
            return self::$owner;
        }

        self::$ownerPid = $pid;

        // A pid that cannot be read is given a token of its own rather than a zero: two such
        // processes on one host would otherwise share an owner, and each would read the other's
        // claim as its own and record an outcome for a run it never made. The token is kept for
        // as long as the pid stays unreadable, because an owner has to be the same string every
        // time; a fork of such a process is the one case this cannot tell apart, and its claim
        // is left to the window.
        return self::$owner = self::host() . '/' . (false === $pid ? 'unknown-' . bin2hex(random_bytes(8)) : $pid);
    }

    /**
     * Only a process of this host can be asked about, and only with ext-posix. Anything else
     * reads as still there, and is left to the reclaim window: a run handed out twice before
     * the window is out is worse than one picked up late.
     */
    private static function host(): string
    {
        if (null !== self::$host) {
            return self::$host;
        }

        $host = gethostname() ?: 'unknown';

        // The pid namespace as well as the name, because a pid only means anything inside the
        // one it was taken in. Containers sharing the host's network namespace — `docker run
        // --network host`, a pod with `hostNetwork: true` — report the same name while keeping
        // their own pids, so a claim of one would be checked against a pid of the other and a
        // live run reclaimed at once, which is exactly what the window exists to prevent.
        $namespace = is_file('/proc/self/ns/pid') ? fileinode('/proc/self/ns/pid') : false;

        return self::$host = false === $namespace ? $host : $host . ':' . $namespace;
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
        $this->claimedTasks = [];

        foreach ($this->records as $taskId => $_) {
            $this->trackClaimedTask($taskId);
        }

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
     * @return array{DateTimeImmutable, TaskStatus, string|null, DateTimeImmutable|null, array<string, array{string, DateTimeImmutable}>}
     */
    private function readRecord(mixed $stored, string $taskId): array
    {
        // A record written before a run carried what became of it holds the time on its own.
        // Such a run is over either way, so it reads as done and the task resumes after it.
        $status = TaskStatus::Done;
        $owner = null;
        $at = null;
        $claims = [];

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

            /** @var mixed $storedClaims */
            $storedClaims = $stored['claims'] ?? null;

            if (null !== $storedClaims) {
                if (!is_array($storedClaims)) {
                    throw new RuntimeException($this->unexpectedTaskPayload($taskId));
                }

                /** @var mixed $claimEntry */
                foreach ($storedClaims as $claimKey => $claimEntry) {
                    if (!is_string($claimKey) || !is_array($claimEntry)) {
                        throw new RuntimeException($this->unexpectedTaskPayload($taskId));
                    }

                    $claimTime = DateTimeImmutable::createFromFormat('U.u', $claimKey);

                    if (!$claimTime instanceof DateTimeImmutable) {
                        throw new RuntimeException($this->unexpectedTaskPayload($taskId));
                    }

                    /** @var mixed $claimOwner */
                    $claimOwner = $claimEntry['owner'] ?? null;

                    if (null !== $claimOwner && !is_string($claimOwner)) {
                        throw new RuntimeException($this->unexpectedTaskPayload($taskId));
                    }

                    /** @var mixed $claimAtRaw */
                    $claimAtRaw = $claimEntry['at'] ?? null;
                    $claimAt = $claimTime;

                    if (null !== $claimAtRaw) {
                        $parsedAt = is_string($claimAtRaw)
                            ? DateTimeImmutable::createFromFormat('U.u', $claimAtRaw)
                            : false;

                        if (!$parsedAt instanceof DateTimeImmutable) {
                            throw new RuntimeException($this->unexpectedTaskPayload($taskId));
                        }

                        $claimAt = $parsedAt;
                    }

                    $claims[$claimKey] = [$claimOwner ?? '', $claimAt];
                }

                ksort($claims);
            }

            $owner = $holder;
            /** @var mixed $stored */
            $stored = $stored['time'];
        }

        $time = is_string($stored) ? DateTimeImmutable::createFromFormat('U.u', $stored) : false;

        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException($this->unexpectedTaskPayload($taskId));
        }

        if ([] === $claims) {
            if (TaskStatus::Claimed === $status && null !== $owner) {
                $claims[$time->format('U.u')] = [$owner, $at ?? $time];
            } elseif (TaskStatus::Released === $status) {
                $claims[$time->format('U.u')] = ['', $time];
            }
        }

        return [$time, $status, $owner, $at, $claims];
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
     * The key a task keeps its record under.
     *
     * Both halves of the identity are hashed rather than spelled out: {@see
     * \Yiisoft\Scheduler\RecurringTask::withId()} takes any string and a schedule name is as
     * free, while PSR-16 reserves `{}()/\@:` in a key and guarantees keys only up to 64
     * characters. Two fixed-width hashes hold a task key at 57 of them however long the schedule
     * and the task are named, and still leave the tasks of one schedule a shared prefix.
     */
    private function taskKey(string $taskId): string
    {
        return self::CACHE_KEY_PREFIX . hash('xxh3', $this->scheduleName) . '_' . hash('xxh3', $taskId);
    }
}
