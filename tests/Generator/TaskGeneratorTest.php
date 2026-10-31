<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Generator;

use ArrayObject;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Exception\RuntimeException;
use Yiisoft\Scheduler\Generator\TaskGenerator;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\Tests\Support\InMemoryCache;
use Yiisoft\Scheduler\TaskContext;
use Yiisoft\Scheduler\TaskStatus;
use Yiisoft\Scheduler\Tests\Support\TestClock;

use function gethostname;
use function hash;
use function pcntl_fork;
use function pcntl_waitpid;

/**
 * The checkpoint records which runs of a shared due time were already emitted, so that a process
 * resuming after a crash replays the rest and only the rest.
 *
 * It has to reach that verdict from the task identifiers: a deploy that adds or removes a task
 * moves the position of every task after it, while the runs recorded before the crash stay as
 * they were.
 */
final class TaskGeneratorTest extends TestCase
{
    public function testRunsEmittedBeforeACrashAreNotRepeated(): void
    {
        $log = new ArrayObject();
        $first = static function () use ($log): void {
            $log[] = 'first';
        };
        $second = static function () use ($log): void {
            $log[] = 'second';
        };
        $third = static function () use ($log): void {
            $log[] = 'third';
        };

        $cache = new InMemoryCache();
        $clock = new TestClock();

        $this->crashAfterTwoRuns($cache, $clock, [$first, $second, $third]);
        $this->assertSame(['first', 'second'], $log->getArrayCopy());

        $log->exchangeArray([]);
        $this->tick($cache, $clock, [$first, $second, $third]);

        $this->assertSame(['third'], $log->getArrayCopy());
    }

    public function testRunLeftUnstartedByACrashIsStillDueAfterATaskIsRemoved(): void
    {
        $log = new ArrayObject();
        $first = static function () use ($log): void {
            $log[] = 'first';
        };
        $second = static function () use ($log): void {
            $log[] = 'second';
        };
        $third = static function () use ($log): void {
            $log[] = 'third';
        };

        $cache = new InMemoryCache();
        $clock = new TestClock();

        $this->crashAfterTwoRuns($cache, $clock, [$first, $second, $third]);
        $log->exchangeArray([]);

        // The deploy that follows the crash drops the task that ran first.
        $this->tick($cache, $clock, [$second, $third]);

        $this->assertSame(['third'], $log->getArrayCopy());
    }

    public function testRunsEmittedBeforeACrashAreNotRepeatedAfterATaskIsAdded(): void
    {
        $log = new ArrayObject();
        $added = static function () use ($log): void {
            $log[] = 'added';
        };
        $first = static function () use ($log): void {
            $log[] = 'first';
        };
        $second = static function () use ($log): void {
            $log[] = 'second';
        };
        $third = static function () use ($log): void {
            $log[] = 'third';
        };

        $cache = new InMemoryCache();
        $clock = new TestClock();

        $this->crashAfterTwoRuns($cache, $clock, [$first, $second, $third]);
        $log->exchangeArray([]);

        // The deploy that follows the crash puts a new task ahead of the three.
        $this->tick($cache, $clock, [$added, $first, $second, $third]);

        $this->assertSame(['added', 'third'], $log->getArrayCopy());
    }

    #[RequiresPhpExtension('posix')]
    #[RequiresPhpExtension('pcntl')]
    public function testClaimOfAProcessThatIsGoneIsHandedOutAgain(): void
    {
        $log = new ArrayObject();
        $task = static function () use ($log): void {
            $log[] = 'task';
        };

        $clock = new TestClock();
        $cache = new InMemoryCache();

        // A pid that has been and is no more, so asking about it is answered rather than guessed.
        $pid = pcntl_fork();

        if (0 === $pid) {
            exit(0);
        }

        pcntl_waitpid($pid, $status);

        $claimedAt = $clock->now()->modify('+1 minute');
        $this->leaveClaim($cache, $task, $claimedAt, (gethostname() ?: 'unknown') . '/' . $pid);

        $clock->set($claimedAt->modify('+30 seconds'));

        // The window is a year out, so only the process being gone can account for the run
        // being handed out again.
        $this->tick($cache, $clock, [$task], 31_536_000);

        $this->assertSame(['task'], $log->getArrayCopy());
    }

    public function testClaimOfAnotherHostIsLeftUntilTheReclaimWindowRunsOut(): void
    {
        $log = new ArrayObject();
        $task = static function () use ($log): void {
            $log[] = 'task';
        };

        $clock = new TestClock();
        $cache = new InMemoryCache();

        $claimedAt = $clock->now()->modify('+1 minute');
        $this->leaveClaim($cache, $task, $claimedAt, 'another-host/1');

        // A process of another host cannot be asked about, so the run stays taken while the
        // window holds.
        $clock->set($claimedAt->modify('+30 seconds'));
        $this->tick($cache, $clock, [$task], 3600);

        $this->assertSame([], $log->getArrayCopy());

        // Once the window runs out it is handed out again, whatever host made the claim.
        $this->tick($cache, $clock, [$task], 1);

        $this->assertSame(['task'], $log->getArrayCopy());
    }

    public function testResolvingAnEarlierRunDoesNotHandOutTheOnesAfterItAgain(): void
    {
        $log = new ArrayObject();
        $task = static function () use ($log): void {
            $log[] = 'task';
        };

        $claimed = $this->claimThreeMissedRuns($cache, $clock, $task, $generator);

        // The outcomes arrive out of the order the runs were claimed in: the later ones are
        // skipped at once because the first is still going, and the first is resolved last,
        // when its process is reaped.
        $generator->resolve($claimed[2], TaskStatus::Skipped);
        $generator->resolve($claimed[1], TaskStatus::Skipped);
        $generator->resolve($claimed[0], TaskStatus::Done);
        unset($generator);

        $this->tick($cache, $clock, [$task]);

        $this->assertSame([], $log->getArrayCopy());
    }

    public function testGivingBackSeveralRunsLeavesACompletedOneAlone(): void
    {
        $log = new ArrayObject();
        $task = static function () use ($log): void {
            $log[] = 'task';
        };

        $claimed = $this->claimThreeMissedRuns($cache, $clock, $task, $generator);

        // The first run was started and reaped; the stop then gets to the other two.
        $generator->resolve($claimed[0], TaskStatus::Done);
        $generator->release($claimed[1]);
        $generator->release($claimed[2]);
        unset($generator);

        $this->tick($cache, $clock, [$task]);

        // The two given back are due again; the one that ran is not.
        $this->assertSame(['task', 'task'], $log->getArrayCopy());
    }

    public function testClaimExpiringUnderALongLivedSchedulerIsStillHandedOut(): void
    {
        $log = new ArrayObject();
        $task = static function () use ($log): void {
            $log[] = 'task';
        };

        $clock = new TestClock();
        $cache = new InMemoryCache();

        $claimedAt = $clock->now()->modify('+1 minute');
        $this->leaveClaim($cache, $task, $claimedAt, 'another-host/1');

        // One scheduler for both ticks, so the pending runs it works out are the cached ones.
        $schedule = $this->schedule($cache, [$task], 60);
        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $clock->set($claimedAt->modify('+30 seconds'));
        $scheduler->tick();

        $this->assertSame([], $log->getArrayCopy());

        $clock->set($claimedAt->modify('+90 seconds'));
        $scheduler->tick();

        // The claim has run out of window, so its run is handed out alongside the one that came
        // due in the meantime, rather than being left behind by the cached heap.
        $this->assertSame(['task', 'task'], $log->getArrayCopy());
    }

    public function testClaimGivenBackIsDueAgain(): void
    {
        $log = new ArrayObject();
        $task = static function () use ($log): void {
            $log[] = 'task';
        };

        $cache = new InMemoryCache();
        $clock = new TestClock();
        $schedule = $this->schedule($cache, [$task]);

        (new Scheduler([$schedule], new CallableTaskHandler(), $clock))->tick();
        $clock->advance('+1 minute');

        $generator = new TaskGenerator($schedule, $clock);
        $claimed = null;

        foreach ($generator->getTasks() as $context => $_) {
            // The consumer is stopping and will not get to this one.
            $claimed = $context;
            break;
        }

        $this->assertNotNull($claimed);
        $generator->release($claimed);
        unset($generator);

        // Given back rather than passed off as a run that happened, so it is still due.
        $this->tick($cache, $clock, [$task]);

        $this->assertSame(['task'], $log->getArrayCopy());
    }

    public function testClaimOfAProcessThatIsStillThereIsLeftAlone(): void
    {
        $log = new ArrayObject();
        $task = static function () use ($log): void {
            $log[] = 'task';
        };

        $cache = new InMemoryCache();
        $clock = new TestClock();
        $schedule = $this->schedule($cache, [$task]);

        (new Scheduler([$schedule], new CallableTaskHandler(), $clock))->tick();
        $clock->advance('+1 minute');

        $generator = new TaskGenerator($schedule, $clock);

        foreach ($generator->getTasks() as $context => $_) {
            // Handed out and still in flight: the claim is neither resolved nor given back.
            break;
        }

        // The process that holds it is this one, and the reclaim window has not run out, so the
        // run stays taken. Handing it out again here would run the task a second time.
        $this->tick($cache, $clock, [$task]);

        $this->assertSame([], $log->getArrayCopy());
    }

    public function testRunsAlreadyRecordedAreNotWrittenToTheCheckpointAgain(): void
    {
        $log = new ArrayObject();
        $first = static function () use ($log): void {
            $log[] = 'first';
        };
        $second = static function () use ($log): void {
            $log[] = 'second';
        };
        $third = static function () use ($log): void {
            $log[] = 'third';
        };

        $cache = new InMemoryCache();
        $clock = new TestClock();

        $this->crashAfterTwoRuns($cache, $clock, [$first, $second, $third]);
        $writes = $cache->writes();

        $this->tick($cache, $clock, [$first, $second, $third]);

        // The two tasks that already have a record are picked up after it and write nothing. What
        // is written is the run taken back: claimed as it is handed out and recorded once it is
        // over, plus the position of the tick — not a record per task in the schedule.
        $this->assertSame(3, $cache->writes() - $writes);
    }

    public function testCheckpointWrittenBeforeRunsWereRecordedByIdResumesWithoutReplaying(): void
    {
        $log = new ArrayObject();
        $task = static function () use ($log): void {
            $log[] = 'task';
        };

        $clock = new TestClock();
        $cache = new InMemoryCache();

        // The position a checkpoint held before the emitted runs were recorded by task id.
        $cache->set('yii_schedule_checkpoint_default', ['time' => $clock->now()->format('U.u'), 'index' => 1]);

        $this->tick($cache, $clock, [$task]);
        $this->assertSame([], $log->getArrayCopy());

        $clock->advance('+1 minute');
        $this->tick($cache, $clock, [$task]);

        $this->assertSame(['task'], $log->getArrayCopy());
    }

    public function testRecordOfATaskHoldingSomethingOtherThanATimeIsRejected(): void
    {
        $log = new ArrayObject();
        $task = static function () use ($log): void {
            $log[] = 'task';
        };

        $cache = new InMemoryCache();
        $cache->set('yii_schedule_checkpoint_default_' . hash('xxh3', RecurringTask::cron('* * * * *', $task)->getId()), 'garbage');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected payload');

        $this->tick($cache, new TestClock(), [$task]);
    }

    public function testEachTaskKeepsARecordOfItsOwn(): void
    {
        $log = new ArrayObject();
        $first = static function () use ($log): void {
            $log[] = 'first';
        };
        $second = static function () use ($log): void {
            $log[] = 'second';
        };

        $cache = new InMemoryCache();
        $clock = new TestClock();

        $this->tick($cache, $clock, [$first, $second]);
        $clock->advance('+1 minute');
        $this->tick($cache, $clock, [$first, $second]);

        $this->assertSame(['first', 'second'], $log->getArrayCopy());

        // What the schedule itself holds is the time it was last evaluated to the end, and
        // nothing that grows with the number of tasks sharing a due time.
        $this->assertSame(['time' => $clock->now()->format('U.u')], $cache->get('yii_schedule_checkpoint_default'));

        foreach ([$first, $second] as $task) {
            $key = 'yii_schedule_checkpoint_default_' . hash('xxh3', RecurringTask::cron('* * * * *', $task)->getId());

            // A run that is over keeps no owner: nobody is going to report anything about it.
            $this->assertSame(
                ['time' => $clock->now()->format('U.u'), 'status' => 'done', 'owner' => null, 'at' => null],
                $cache->get($key),
            );
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedCheckpoints(): iterable
    {
        yield 'not an array' => ['garbage'];
        yield 'time is missing' => [['tasks' => []]];
        yield 'time is not a string' => [['time' => 1767225660, 'tasks' => []]];
    }

    #[DataProvider('malformedCheckpoints')]
    public function testMalformedCheckpointIsRejected(mixed $state): void
    {
        $cache = new InMemoryCache();
        $cache->set('yii_schedule_checkpoint_default', $state);

        $log = new ArrayObject();
        $task = static function () use ($log): void {
            $log[] = 'task';
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected payload');

        $this->tick($cache, new TestClock(), [$task]);
    }

    /**
     * Claims the three runs a task missed while the schedule was down, without resolving any.
     *
     * @return list<TaskContext>
     */
    private function claimThreeMissedRuns(
        ?InMemoryCache &$cache,
        ?TestClock &$clock,
        callable $task,
        ?TaskGenerator &$generator,
    ): array {
        $cache = new InMemoryCache();
        $clock = new TestClock();
        $schedule = $this->schedule($cache, [$task]);

        (new Scheduler([$schedule], new CallableTaskHandler(), $clock))->tick();
        $clock->advance('+3 minutes');

        $generator = new TaskGenerator($schedule, $clock);
        $claimed = [];

        foreach ($generator->getTasks() as $context => $_) {
            $claimed[] = $context;
        }

        $this->assertCount(3, $claimed);

        return $claimed;
    }

    /**
     * Emits the due runs one by one and stops before starting the third, the way a process killed
     * mid-tick leaves the two runs before it recorded, the one in hand claimed with nobody left to
     * resolve it, and the ones behind it untouched.
     *
     * @param list<callable> $tasks
     */
    private function crashAfterTwoRuns(InMemoryCache $cache, TestClock $clock, array $tasks): void
    {
        $schedule = $this->schedule($cache, $tasks);
        $handler = new CallableTaskHandler();

        (new Scheduler([$schedule], $handler, $clock))->tick();
        $clock->advance('+1 minute');

        $generator = new TaskGenerator($schedule, $clock);
        $started = 0;

        foreach ($generator->getTasks() as $context => $task) {
            if (2 === $started) {
                // The process dies here, holding a claim it will never resolve.
                break;
            }

            $handler->handle($task->getTask(), $context);
            $generator->resolve($context, TaskStatus::Done);
            ++$started;
        }

        unset($generator);

        // Nothing is going to report what became of that claim, so the schedule takes it back
        // once its reclaim window runs out. Everything below resumes past that point.
        $clock->advance('+30 seconds');
    }

    /**
     * @param list<callable> $tasks
     */
    private function tick(InMemoryCache $cache, TestClock $clock, array $tasks, int $reclaimAfter = 1): void
    {
        $schedule = $this->schedule($cache, $tasks, $reclaimAfter);

        (new Scheduler([$schedule], new CallableTaskHandler(), $clock))->tick();
    }

    /**
     * Leaves a claim of the given run in the checkpoint, as a process that never resolved it would.
     */
    private function leaveClaim(InMemoryCache $cache, callable $task, DateTimeImmutable $at, string $owner): void
    {
        $id = RecurringTask::cron('* * * * *', $task)->getId();

        $cache->set('yii_schedule_checkpoint_default_' . hash('xxh3', $id), [
            'time' => $at->format('U.u'),
            'status' => 'claimed',
            'owner' => $owner,
            'at' => $at->format('U.u'),
        ]);
    }

    /**
     * @param list<callable> $tasks
     */
    private function schedule(InMemoryCache $cache, array $tasks, int $reclaimAfter = 1): Schedule
    {
        $schedule = (new Schedule())->stateful($cache)->reclaimAfter($reclaimAfter);

        foreach ($tasks as $task) {
            $schedule->task(RecurringTask::cron('* * * * *', $task));
        }

        return $schedule;
    }
}
