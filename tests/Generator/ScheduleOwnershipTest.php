<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Generator;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Generator\OwnershipStatus;
use Yiisoft\Scheduler\Generator\ProcessOwner;
use Yiisoft\Scheduler\Generator\ScheduleOwnership;
use Yiisoft\Scheduler\Tests\Support\InMemoryCache;

use function function_exists;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Which process plans a schedule.
 *
 * One at a time, found by the name of the schedule however it was spelled, and replaced only
 * once the process holding it is gone or has not been heard from for long enough.
 */
final class ScheduleOwnershipTest extends TestCase
{
    public function testAFreeScheduleIsTakenOn(): void
    {
        $cache = new InMemoryCache();
        $ownership = new ScheduleOwnership('default', $cache);

        $this->assertSame(OwnershipStatus::Held, $ownership->acquire('rev-1', $this->at(), $this->window()));
        $this->assertSame(ProcessOwner::current(), $ownership->holder());
    }

    public function testASecondProcessPlansNothingWhileTheFirstHoldsTheSchedule(): void
    {
        $cache = new InMemoryCache();
        $this->plantOwner($cache, 'default', 'elsewhere/1', 'rev-1', $this->at());

        $ownership = new ScheduleOwnership('default', $cache);

        $this->assertSame(OwnershipStatus::Taken, $ownership->acquire('rev-1', $this->at(), $this->window()));
        $this->assertSame('elsewhere/1', $ownership->holder());
    }

    public function testTwoSchedulesOfTheirOwnAreOwnedApart(): void
    {
        $cache = new InMemoryCache();
        $this->plantOwner($cache, 'reports', 'elsewhere/1', 'rev-1', $this->at());

        $ownership = new ScheduleOwnership('invoices', $cache);

        $this->assertSame(OwnershipStatus::Held, $ownership->acquire('rev-1', $this->at(), $this->window()));
    }

    /**
     * The differences a name picks up between two configuration files are not two schedules,
     * and reading them as two would put two processes on one set of records.
     */
    public function testOneNameSpelledTwoWaysIsOneSchedule(): void
    {
        $cache = new InMemoryCache();
        $this->plantOwner($cache, 'default', 'elsewhere/1', 'rev-1', $this->at());

        foreach ([' default ', 'Default', "default\n"] as $spelling) {
            $this->assertSame(
                OwnershipStatus::Taken,
                (new ScheduleOwnership($spelling, $cache))->acquire('rev-1', $this->at(), $this->window()),
                sprintf('"%s" is the schedule named "default"', $spelling),
            );
        }
    }

    public function testTwoNamesThatDifferByMoreThanSpacingAreTwoSchedules(): void
    {
        $cache = new InMemoryCache();
        $this->plantOwner($cache, 'default', 'elsewhere/1', 'rev-1', $this->at());

        $ownership = new ScheduleOwnership('default reports', $cache);

        $this->assertSame(OwnershipStatus::Held, $ownership->acquire('rev-1', $this->at(), $this->window()));
    }

    public function testAScheduleHandedBackIsFreeForTheNextProcess(): void
    {
        $cache = new InMemoryCache();
        $ownership = new ScheduleOwnership('default', $cache);

        $ownership->acquire('rev-1', $this->at(), $this->window());
        $ownership->release();

        $this->assertNull($ownership->holder());
        $this->assertSame(
            OwnershipStatus::Held,
            (new ScheduleOwnership('default', $cache))->acquire('rev-1', $this->at(), $this->window()),
        );
    }

    /**
     * A planner that was killed says nothing on the way out. The next process takes the
     * schedule over once the window since that one was last heard from has run out.
     */
    public function testACrashedOwnerIsReplacedOnceTheWindowHasRunOut(): void
    {
        $cache = new InMemoryCache();
        $this->plantOwner($cache, 'default', 'elsewhere/1', 'rev-1', $this->at());

        $ownership = new ScheduleOwnership('default', $cache);
        $window = new DateInterval('PT1M');

        $this->assertSame(OwnershipStatus::Taken, $ownership->acquire('rev-1', $this->at('+59 seconds'), $window));
        $this->assertSame(OwnershipStatus::Held, $ownership->acquire('rev-1', $this->at('+1 minute'), $window));
        $this->assertSame(ProcessOwner::current(), $ownership->holder());
    }

    /**
     * A process of this host can be asked about rather than waited out, which is the common
     * case: a `schedule:run` killed mid-tick is replaced by the next one a minute later.
     */
    public function testAnOwnerProvedGoneIsReplacedAtOnce(): void
    {
        $cache = new InMemoryCache();
        $gone = $this->reapedPid();
        $this->plantOwner($cache, 'default', ProcessOwner::host() . '/' . $gone, 'rev-1', $this->at());

        $ownership = new ScheduleOwnership('default', $cache);

        $this->assertSame(OwnershipStatus::Held, $ownership->acquire('rev-1', $this->at(), $this->window()));
    }

    public function testAPlannerThatNeverStartedLeavesNothingBehind(): void
    {
        $cache = new InMemoryCache();
        $this->plantOwner($cache, 'default', 'elsewhere/1', 'rev-1', $this->at());

        $ownership = new ScheduleOwnership('default', $cache);

        $this->assertSame(OwnershipStatus::Taken, $ownership->acquire('rev-1', $this->at(), $this->window()));

        // Releasing what was never acquired leaves the process that does hold it alone.
        $ownership->release();

        $this->assertSame('elsewhere/1', $ownership->holder());
    }

    public function testTasksChangingUnderAnOwnerFenceIt(): void
    {
        $cache = new InMemoryCache();
        $ownership = new ScheduleOwnership('default', $cache);

        $ownership->acquire('rev-1', $this->at(), $this->window());

        $this->assertSame(OwnershipStatus::Fenced, $ownership->keep('rev-2', $this->at('+1 minute'), $this->window()));
    }

    public function testAReplacementPlanningOtherTasksFencesTheOneBeforeIt(): void
    {
        $cache = new InMemoryCache();
        $ownership = new ScheduleOwnership('default', $cache);

        $ownership->acquire('rev-1', $this->at(), $this->window());

        // What a replacement writes once it has taken the schedule over.
        $this->plantOwner($cache, 'default', 'elsewhere/1', 'rev-2', $this->at('+1 minute'));

        $this->assertSame(OwnershipStatus::Fenced, $ownership->keep('rev-1', $this->at('+2 minutes'), $this->window()));
    }

    public function testAnOwnerFencedOnceStaysFenced(): void
    {
        $cache = new InMemoryCache();
        $ownership = new ScheduleOwnership('default', $cache);

        $ownership->acquire('rev-1', $this->at(), $this->window());
        $ownership->keep('rev-2', $this->at('+1 minute'), $this->window());

        // Nothing was written under the tasks it was fenced over.
        $this->assertSame('rev-1', $this->plantedRevision($cache, 'default'));
    }

    public function testAnOwnerTakenOverByAProcessPlanningTheSameTasksPlansNothing(): void
    {
        $cache = new InMemoryCache();
        $ownership = new ScheduleOwnership('default', $cache);

        $ownership->acquire('rev-1', $this->at(), $this->window());
        $this->plantOwner($cache, 'default', 'elsewhere/1', 'rev-1', $this->at('+1 minute'));

        $this->assertSame(OwnershipStatus::Taken, $ownership->keep('rev-1', $this->at('+2 minutes'), $this->window()));
    }

    public function testTheOwnerIsSaidToBeThereAgainOnlyOnceTheWindowIsHalfSpent(): void
    {
        $cache = new InMemoryCache();
        $ownership = new ScheduleOwnership('default', $cache);
        $window = new DateInterval('PT1M');

        $ownership->acquire('rev-1', $this->at(), $window);
        $writes = $cache->writes();

        $this->assertSame(OwnershipStatus::Held, $ownership->keep('rev-1', $this->at('+29 seconds'), $window));
        $this->assertSame(0, $cache->writes() - $writes, 'a tick is not news');

        $this->assertSame(OwnershipStatus::Held, $ownership->keep('rev-1', $this->at('+30 seconds'), $window));
        $this->assertSame(1, $cache->writes() - $writes, 'the record is older than half the window');
    }

    /**
     * Half a record names nobody. Read as an owner that can never be proved gone, it would
     * keep every process out of the schedule until the window ran out.
     */
    public function testAHalfWrittenRecordIsNoOwner(): void
    {
        $cache = new InMemoryCache();
        $cache->set(ScheduleOwnership::keyFor('default'), ['owner' => 'elsewhere/1']);

        $ownership = new ScheduleOwnership('default', $cache);

        $this->assertSame(OwnershipStatus::Held, $ownership->acquire('rev-1', $this->at(), $this->window()));
    }

    public function testAScheduleWithNoStateIsAlwaysThisProcesssToPlan(): void
    {
        $ownership = new ScheduleOwnership('default');

        $this->assertSame(OwnershipStatus::Held, $ownership->acquire('rev-1', $this->at(), $this->window()));
        $this->assertSame(OwnershipStatus::Held, $ownership->keep('rev-1', $this->at(), $this->window()));
        $this->assertNull($ownership->holder());
    }

    public function testAScheduleWithNoStateStillFencesTasksThatChangedUnderIt(): void
    {
        $ownership = new ScheduleOwnership('default');

        $ownership->acquire('rev-1', $this->at(), $this->window());

        $this->assertSame(OwnershipStatus::Fenced, $ownership->keep('rev-2', $this->at(), $this->window()));
    }

    public function testTheTasksOfASchedulePlusOneReadDifferently(): void
    {
        $this->assertNotSame(
            ScheduleOwnership::revisionOf(['a', 'b']),
            ScheduleOwnership::revisionOf(['a', 'b', 'c']),
        );
        $this->assertSame(
            ScheduleOwnership::revisionOf(['a', 'b']),
            ScheduleOwnership::revisionOf(['a', 'b']),
        );
        $this->assertNotSame(
            ScheduleOwnership::revisionOf(['a', 'b']),
            ScheduleOwnership::revisionOf(['b', 'a']),
        );
    }

    /**
     * The record another process would have left, written straight into the state.
     */
    private function plantOwner(
        InMemoryCache $cache,
        string $schedule,
        string $owner,
        string $revision,
        DateTimeImmutable $seen,
    ): void {
        $cache->set(ScheduleOwnership::keyFor($schedule), [
            'owner' => $owner,
            'revision' => $revision,
            'since' => $seen->format('U.u'),
            'seen' => $seen->format('U.u'),
        ]);
    }

    private function plantedRevision(InMemoryCache $cache, string $schedule): ?string
    {
        /** @var mixed $record */
        $record = $cache->get(ScheduleOwnership::keyFor($schedule));

        if (!is_array($record) || !isset($record['revision']) || !is_string($record['revision'])) {
            return null;
        }

        return $record['revision'];
    }

    /**
     * A pid of this host that is certainly not a process any more.
     */
    private function reapedPid(): int
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('A pid known to be gone needs ext-pcntl.');
        }

        $pid = pcntl_fork();

        if (0 === $pid) {
            exit(0);
        }

        if (-1 === $pid) {
            $this->markTestSkipped('This build of PHP cannot fork.');
        }

        pcntl_waitpid($pid, $status);

        return $pid;
    }

    private function at(string $modifier = 'now'): DateTimeImmutable
    {
        $base = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        return 'now' === $modifier ? $base : $base->modify($modifier);
    }

    private function window(): DateInterval
    {
        return new DateInterval('P1D');
    }
}
