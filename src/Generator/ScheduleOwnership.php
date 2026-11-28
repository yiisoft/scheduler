<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Generator;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Scheduler\Exception\LockTimeoutException;
use Yiisoft\Scheduler\Exception\RuntimeException;
use Yiisoft\Scheduler\Lock\LockInterface;

use function hash;
use function implode;
use function intdiv;
use function max;
use function is_array;
use function is_string;
use function preg_replace;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Which process plans one schedule, and which tasks it took the schedule on with.
 *
 * The schedule lock keeps two processes out of one tick. This is the longer contract above it:
 * one process plans a schedule from the moment it starts until it stops, and a second process
 * started against the same schedule plans nothing at all rather than taking every other tick.
 *
 * Ownership is kept per schedule, found by the name with the differences a retyped name picks
 * up taken out, so two spellings of one name are one schedule rather than two owners of the
 * same records. The tasks the owner took it on with are kept beside it as a revision: a process
 * whose tasks have changed under it is fenced, because what it planned was worked out from a
 * schedule that is no longer the one the records belong to.
 *
 * An owner that stops releases it. One that is killed leaves the record behind, and the next
 * process takes over as soon as it can prove that process is gone, or once the replacement
 * window has run out where it cannot. Ownership of a schedule is not ownership of a run: taking
 * a schedule over says nothing about the tasks a dead owner had started, which stay claimed
 * until the checkpoint hands them back.
 *
 * @internal
 *
 * @phpstan-type Record = array{owner: string, revision: string, since: string, seen: string}
 */
final class ScheduleOwnership
{
    private const CACHE_KEY_PREFIX = 'yii_schedule_owner_';

    /**
     * How long to wait for the schedule lock before giving up on taking the schedule over.
     * The same bound the checkpoint writes under, for the same reason: long enough to outlast
     * another process's read and write, short enough not to wait on a lock nobody will give up.
     */
    private const WRITE_LOCK_TIMEOUT_SECONDS = 10;

    /** The revision this process took the schedule on with, while it holds it. */
    private ?string $pinned = null;

    public function __construct(
        private readonly string $scheduleName,
        private readonly ?CacheInterface $state = null,
        private readonly ?LockInterface $lock = null,
    ) {}

    /**
     * Takes the schedule on, or says who has it.
     *
     * @param string $revision What the tasks of the schedule are, as {@see revisionOf()} writes
     * them down.
     * @param DateInterval $replaceAfter How long an owner that has not been heard from is left
     * before another process may replace it.
     */
    public function acquire(string $revision, DateTimeImmutable $now, DateInterval $replaceAfter): OwnershipStatus
    {
        // A schedule with no state has nowhere to keep an owner, and nothing a second process
        // could read if it had one. Its lock is the whole of its coordination.
        if (null === $this->state) {
            $this->pinned = $revision;

            return OwnershipStatus::Held;
        }

        return $this->exclusively(function () use ($revision, $now, $replaceAfter): OwnershipStatus {
            $record = $this->read();
            $me = ProcessOwner::current();

            if (null !== $record && $record['owner'] !== $me && !self::isReplaceable($record, $now, $replaceAfter)) {
                return OwnershipStatus::Taken;
            }

            // A record of this process with other tasks in it is the planner before this one,
            // which the process outlived: taking the schedule over is what starting is, and a
            // second owner is not what a changed configuration makes.
            $continuing = null !== $record && $record['owner'] === $me && $record['revision'] === $revision;

            // Carrying on where this process left off, recently enough that the record already
            // says so. A process driven a tick at a time writes nothing to start a tick.
            if ($continuing && !self::isStale($record, $now, $replaceAfter)) {
                $this->pinned = $revision;

                return OwnershipStatus::Held;
            }

            $this->write([
                'owner' => $me,
                'revision' => $revision,
                'since' => $continuing ? $record['since'] : self::stamp($now),
                'seen' => self::stamp($now),
            ]);

            $this->pinned = $revision;

            return OwnershipStatus::Held;
        });
    }

    /**
     * Says this process is still there, and answers whether the schedule is still its to plan.
     */
    public function keep(string $revision, DateTimeImmutable $now, DateInterval $replaceAfter): OwnershipStatus
    {
        if (null === $this->state) {
            return null === $this->pinned || $this->pinned === $revision
                ? OwnershipStatus::Held
                : OwnershipStatus::Fenced;
        }

        if (null !== $this->pinned && $this->pinned !== $revision) {
            $this->pinned = null;

            return OwnershipStatus::Fenced;
        }

        return $this->exclusively(function () use ($revision, $now, $replaceAfter): OwnershipStatus {
            $record = $this->read();
            $me = ProcessOwner::current();

            // Taken over by a process planning other tasks, or holding other tasks itself:
            // either way the schedule this process took on is not the one the records belong
            // to now, and nothing worked out from it may be handed out.
            if (null !== $record && $record['revision'] !== $revision) {
                $this->pinned = null;

                return OwnershipStatus::Fenced;
            }

            // Gone, or another process's with the same tasks: this one may take it back where
            // that process is gone too, and plans nothing while it is not.
            if (null === $record || $record['owner'] !== $me) {
                $this->pinned = null;

                return $this->acquireUnderLock($revision, $now, $replaceAfter, $record);
            }

            // Written down again only once the window is half spent. A tick is not news, and a
            // record rewritten every tick is a write per tick of every schedule that has one;
            // what the window has to be told is that this process was here recently enough.
            if (self::isStale($record, $now, $replaceAfter)) {
                $record['seen'] = self::stamp($now);
                $this->write($record);
            }

            return OwnershipStatus::Held;
        });
    }

    /**
     * Hands the schedule back, where this process is the one holding it.
     */
    public function release(): void
    {
        // Nothing of this process's to hand back: it never took the schedule on, or it was
        // fenced or replaced, in which case the record belongs to whoever holds it now.
        if (null === $this->pinned) {
            return;
        }

        $this->pinned = null;

        if (null === $this->state) {
            return;
        }

        $this->exclusively(function (): null {
            $record = $this->read();

            if (null !== $record && $record['owner'] === ProcessOwner::current()) {
                $this->delete();
            }

            return null;
        });
    }

    /** Who the record says is planning the schedule, for a process that is not. */
    public function holder(): ?string
    {
        if (null === $this->state) {
            return null;
        }

        return $this->read()['owner'] ?? null;
    }

    /**
     * What the tasks of a schedule are, as one string. Their identifiers in the order they were
     * added: an identifier already carries what a task runs and when, so two schedules of the
     * same tasks read the same and one that gained, lost or redefined a task does not.
     *
     * @param list<string> $taskIds
     */
    public static function revisionOf(array $taskIds): string
    {
        return hash('xxh3', implode("\n", $taskIds));
    }

    /**
     * The schedule a name stands for. A name that was retyped picks up surrounding space, a
     * doubled space and a changed case; none of those is a second schedule, and reading them
     * as one keeps two processes off one set of records.
     */
    public static function canonical(string $name): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', trim($name)));
    }

    /**
     * Where the owner of a schedule is kept. Every spelling of one name answers with one key,
     * which is what keeps two spellings from being two owners.
     */
    public static function keyFor(string $scheduleName): string
    {
        return self::CACHE_KEY_PREFIX . hash('xxh3', self::canonical($scheduleName));
    }

    /**
     * The step of {@see acquire()} that runs with the lock already held, for a keep() that
     * found the schedule free again.
     *
     * @param Record|null $record
     */
    private function acquireUnderLock(
        string $revision,
        DateTimeImmutable $now,
        DateInterval $replaceAfter,
        ?array $record,
    ): OwnershipStatus {
        if (null !== $record && !self::isReplaceable($record, $now, $replaceAfter)) {
            return OwnershipStatus::Taken;
        }

        $this->write([
            'owner' => ProcessOwner::current(),
            'revision' => $revision,
            'since' => self::stamp($now),
            'seen' => self::stamp($now),
        ]);

        $this->pinned = $revision;

        return OwnershipStatus::Held;
    }

    /**
     * Whether the moment a record was last written is far enough behind to write it again: half
     * the window, so that a record is always fresher than the window it is read against.
     *
     * @param Record $record
     */
    private static function isStale(array $record, DateTimeImmutable $now, DateInterval $replaceAfter): bool
    {
        $seen = DateTimeImmutable::createFromFormat('U.u', $record['seen']);

        if (!$seen instanceof DateTimeImmutable) {
            return true;
        }

        return $now->getTimestamp() - $seen->getTimestamp() >= max(1, intdiv(self::seconds($replaceAfter), 2));
    }

    /** A window as a whole number of seconds, which is what two moments can be compared by. */
    private static function seconds(DateInterval $window): int
    {
        $epoch = new DateTimeImmutable('@0');

        return $epoch->add($window)->getTimestamp();
    }

    /**
     * Whether the process named by a record may be replaced: one proved gone may be replaced at
     * once, and one that cannot be asked about once the window since it was last heard from has
     * run out.
     *
     * @param Record $record
     */
    private static function isReplaceable(array $record, DateTimeImmutable $now, DateInterval $replaceAfter): bool
    {
        if (ProcessOwner::isGone($record['owner'])) {
            return true;
        }

        $seen = DateTimeImmutable::createFromFormat('U.u', $record['seen']);

        // A record whose moment cannot be read says nothing about when its owner was last
        // there, which is the one case where the window cannot bound the wait.
        return !$seen instanceof DateTimeImmutable || $seen->add($replaceAfter) <= $now;
    }

    /**
     * @return Record|null
     */
    private function read(): ?array
    {
        if (null === $this->state) {
            return null;
        }

        /** @var mixed $stored */
        $stored = $this->state->get($this->key());

        if (!is_array($stored)) {
            return null;
        }

        foreach (['owner', 'revision', 'since', 'seen'] as $field) {
            if (!isset($stored[$field]) || !is_string($stored[$field])) {
                // Half a record names nobody, so it stands for nobody rather than for a process
                // that can never be replaced.
                return null;
            }
        }

        /** @var Record $stored */
        return $stored;
    }

    /**
     * @param Record $record
     */
    private function write(array $record): void
    {
        if (null === $this->state) {
            return;
        }

        if (!$this->state->set($this->key(), $record)) {
            throw new RuntimeException(sprintf(
                'Unable to write down which process is planning schedule "%s".',
                $this->scheduleName,
            ));
        }
    }

    private function delete(): void
    {
        $this->state?->delete($this->key());
    }

    private function key(): string
    {
        return self::keyFor($this->scheduleName);
    }

    private static function stamp(DateTimeImmutable $now): string
    {
        return $now->format('U.u');
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function exclusively(callable $operation): mixed
    {
        if (null === $this->lock) {
            return $operation();
        }

        // Waiting rather than moving on, which is what a tick does: two processes starting at
        // once have to come away with one answer each about who is planning, and the one that
        // does not wait would read the schedule as free while the other is writing itself in.
        if (!$this->lock->acquire(self::WRITE_LOCK_TIMEOUT_SECONDS)) {
            throw new LockTimeoutException(sprintf(
                'Unable to settle which process is planning schedule "%s": the schedule lock was held'
                . ' for longer than %d seconds.',
                $this->scheduleName,
                self::WRITE_LOCK_TIMEOUT_SECONDS,
            ));
        }

        try {
            return $operation();
        } finally {
            $this->lock->release();
        }
    }
}
