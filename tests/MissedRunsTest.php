<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Generator\TaskGenerator;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\MissedRuns;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\Tests\Support\InMemoryCache;
use Yiisoft\Scheduler\Tests\Support\TestClock;

/**
 * What a tick does with the runs of a task that were due while nothing was there to run them.
 *
 * Every case here is the same outage: a schedule of tasks due every minute, a tick, three
 * minutes of nothing, and a second tick that finds three runs due. The first of the two ticks
 * is where the schedule starts, so it has nothing behind it: a schedule with no checkpoint does
 * not invent the history it was not there for.
 */
final class MissedRunsTest extends TestCase
{
    public function testEveryMissedRunIsReplayedByDefault(): void
    {
        $this->assertSame(3, $this->outage(static fn(RecurringTask $task): RecurringTask => $task));
    }

    public function testOnlyTheNewestRunDueIsReplayedUnderLast(): void
    {
        $this->assertSame(
            1,
            $this->outage(static fn(RecurringTask $task): RecurringTask => $task->withMissedRuns(MissedRuns::Last)),
        );
    }

    public function testNothingIsReplayedUnderSkip(): void
    {
        $this->assertSame(
            0,
            $this->outage(static fn(RecurringTask $task): RecurringTask => $task->withMissedRuns(MissedRuns::Skip)),
        );
    }

    public function testASkippedTaskStillRunsOnceItHasCaughtUp(): void
    {
        $clock = new TestClock();
        $cache = new InMemoryCache();
        $ran = 0;

        $task = RecurringTask::cron('* * * * *', static function () use (&$ran): void {
            ++$ran;
        })->withMissedRuns(MissedRuns::Skip);

        $scheduler = new Scheduler([(new Schedule())->stateful($cache)->task($task)], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+3 minutes');
        $scheduler->tick();

        $this->assertSame(0, $ran, 'three runs were missed, so none of them is made');

        // A tick that finds one run due has missed nothing: the schedule is being kept.
        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertSame(1, $ran);
    }

    public function testTasksOfOneScheduleAnswerForTheirOwnMissedRuns(): void
    {
        $clock = new TestClock();
        $cache = new InMemoryCache();
        $counts = ['all' => 0, 'last' => 0, 'skip' => 0, 'default' => 0];
        $count = static function (string $key) use (&$counts): callable {
            return static function () use ($key, &$counts): void {
                ++$counts[$key];
            };
        };

        $schedule = (new Schedule())
            ->stateful($cache)
            ->missedRuns(MissedRuns::Last)
            ->task(
                RecurringTask::cron('* * * * *', $count('all'))->withId('all')->withMissedRuns(MissedRuns::All),
                RecurringTask::cron('* * * * *', $count('last'))->withId('last')->withMissedRuns(MissedRuns::Last),
                RecurringTask::cron('* * * * *', $count('skip'))->withId('skip')->withMissedRuns(MissedRuns::Skip),
                RecurringTask::cron('* * * * *', $count('default'))->withId('default'),
            );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+3 minutes');
        $scheduler->tick();

        $this->assertSame(
            ['all' => 3, 'last' => 1, 'skip' => 0, 'default' => 1],
            $counts,
            'the schedule answers only for the task that did not',
        );
    }

    public function testATickReplaysAtMostTheRunsItsLimitAllowsAndLeavesTheRestBehind(): void
    {
        $clock = new TestClock();
        $cache = new InMemoryCache();
        $ran = [];

        $task = RecurringTask::cron('* * * * *', static function () use (&$ran): void {
            $ran[] = null;
        })->withMissedRuns(MissedRuns::All, 2);

        $scheduler = new Scheduler([(new Schedule())->stateful($cache)->task($task)], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+5 minutes');
        $scheduler->tick();

        $this->assertCount(2, $ran, 'a tick stops at its limit');

        // Nothing is lost: the next tick seeds from the checkpoint and carries on. It reaches
        // the run due now, which the limit does not hold back, so the backlog is drained here.
        $scheduler->tick();

        $this->assertCount(5, $ran, 'two more missed runs, and the run due now behind them');

        $scheduler->tick();

        $this->assertCount(5, $ran, 'nothing is due that was not already made');
    }

    public function testTheRemainderOfAReplayOutlivesTheProcessThatDidNotReachIt(): void
    {
        $clock = new TestClock();
        $cache = new InMemoryCache();
        $ran = 0;
        $build = static function () use ($cache, $clock, &$ran): Scheduler {
            $task = RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                ++$ran;
            })->withMissedRuns(MissedRuns::All, 2);

            return new Scheduler([(new Schedule())->stateful($cache)->task($task)], new CallableTaskHandler(), $clock);
        };

        $build()->tick();
        $clock->advance('+5 minutes');
        $build()->tick();

        $this->assertSame(2, $ran);

        // Another process entirely, reading the same checkpoint.
        $build()->tick();

        $this->assertSame(5, $ran);
    }

    public function testAScheduleWithNoCheckpointDoesNotReplayAHistoryItWasNotThereFor(): void
    {
        $clock = new TestClock();
        $clock->advance('+1 year');
        $ran = 0;

        $task = RecurringTask::cron('* * * * *', static function () use (&$ran): void {
            ++$ran;
        })->withMissedRuns(MissedRuns::All);

        $scheduler = new Scheduler(
            [(new Schedule())->stateful(new InMemoryCache())->task($task)],
            new CallableTaskHandler(),
            $clock,
        );

        $scheduler->tick();

        $this->assertSame(0, $ran, 'a first tick starts the schedule rather than catching it up');
    }

    public function testAnswerAndIdentifierAreSeparate(): void
    {
        $task = RecurringTask::cron('* * * * *', 'report:daily');

        $this->assertSame(
            $task->getId(),
            $task->withMissedRuns(MissedRuns::Skip)->getId(),
            'changing the answer does not orphan the runs recorded under the task',
        );
    }

    public function testTheAnswerSurvivesAnIdentifierOfItsOwn(): void
    {
        $task = RecurringTask::cron('* * * * *', 'report:daily')
            ->withMissedRuns(MissedRuns::All, 7)
            ->withId('report');

        $this->assertSame(MissedRuns::All, $task->getMissedRuns());
        $this->assertSame(7, $task->getMissedRunLimit());
    }

    public function testATaskThatHasNotAnsweredSaysSo(): void
    {
        $this->assertNull(RecurringTask::cron('* * * * *', 'report:daily')->getMissedRuns());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function rejectedLimits(): iterable
    {
        yield 'none' => [0];
        yield 'fewer than none' => [-1];
    }

    #[DataProvider('rejectedLimits')]
    public function testATickReplayingNoneOfThemIsNotALimit(int $limit): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be positive');

        RecurringTask::cron('* * * * *', 'report:daily')->withMissedRuns(MissedRuns::All, $limit);
    }

    public function testOnlyTheAnswerThatReplaysIsBounded(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Only MissedRuns::All replays missed runs');

        RecurringTask::cron('* * * * *', 'report:daily')->withMissedRuns(MissedRuns::Last, 5);
    }

    public function testAScheduleIsHeldToTheSameLimits(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must be positive');

        (new Schedule())->missedRuns(MissedRuns::All, 0);
    }

    public function testARunAlreadyClaimedIsHandedOutAgainWhateverTheAnswerWas(): void
    {
        $clock = new TestClock();
        $cache = new InMemoryCache();
        $ran = 0;

        $task = RecurringTask::every(60, static function () use (&$ran): void {
            ++$ran;
        })->withId('minute')->withMissedRuns(MissedRuns::Skip);

        $schedule = (new Schedule())->stateful($cache)->reclaimAfter(1)->task($task);
        $handler = new CallableTaskHandler();

        (new Scheduler([$schedule], $handler, $clock))->tick();
        $clock->advance('+1 minute');

        // A process takes the run that is due and ends without saying what became of it.
        $generator = new TaskGenerator($schedule, $clock);

        foreach ($generator->getTasks() as $_) {
            break;
        }

        unset($generator);
        $this->assertSame(0, $ran, 'the run was claimed by the process that went away');

        $clock->advance('+3 minutes');
        (new Scheduler([$schedule], $handler, $clock))->tick();

        $this->assertSame(
            1,
            $ran,
            'the claimed run is admitted work and comes back; the runs missed behind it are the answer\'s to drop',
        );
    }

    public function testTheRunDueNowIsNotHeldBackByTheLimit(): void
    {
        $clock = new TestClock();
        $cache = new InMemoryCache();
        $ran = 0;

        // Two runs are missed and a third is due now, against a limit of two: the limit counts
        // the runs the task missed, and the one due now is not one of them.
        $task = RecurringTask::cron('* * * * *', static function () use (&$ran): void {
            ++$ran;
        })->withMissedRuns(MissedRuns::All, 2);

        $scheduler = new Scheduler([(new Schedule())->stateful($cache)->task($task)], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+3 minutes');
        $scheduler->tick();

        $this->assertSame(3, $ran, 'the two missed runs, and the run due now behind them');
    }

    /**
     * Three runs missed, and what the given answer makes of them.
     *
     * @param callable(RecurringTask): RecurringTask $answer
     */
    private function outage(callable $answer): int
    {
        $clock = new TestClock();
        $ran = 0;

        $task = $answer(RecurringTask::cron('* * * * *', static function () use (&$ran): void {
            ++$ran;
        }));

        $scheduler = new Scheduler(
            [(new Schedule())->stateful(new InMemoryCache())->task($task)],
            new CallableTaskHandler(),
            $clock,
        );

        $scheduler->tick();
        $clock->advance('+3 minutes');
        $scheduler->tick();

        return $ran;
    }
}
