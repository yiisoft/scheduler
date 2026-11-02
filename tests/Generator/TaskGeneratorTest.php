<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Generator;

use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Exception\RuntimeException;
use Yiisoft\Scheduler\Generator\TaskGenerator;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\Tests\Support\InMemoryCache;
use Yiisoft\Scheduler\Tests\Support\TestClock;

use function hash;

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

        // The two tasks that already have a record are picked up after it and write nothing;
        // what is written is the record of the run left unstarted and the position of the tick,
        // rather than a record per task in the schedule.
        $this->assertSame(2, $cache->writes() - $writes);
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
        $cache->set($this->taskKey($task), 'garbage');

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
            $this->assertSame($clock->now()->format('U.u'), $cache->get($this->taskKey($task)));
        }
    }

    public function testRunLeftUnstartedByAnInterruptedFirstTickIsStillDue(): void
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

        // A daemon asks when the schedule is next due before anything has ever run, so this is
        // the first time the pending runs are computed from a state holding nothing at all.
        $generator = new TaskGenerator($this->schedule($cache, [$first, $second]), $clock);
        $this->assertNotNull($generator->getNextRunDate());

        // The tick it wakes up for is killed between the two runs, so it never drains and never
        // records that the schedule was evaluated to the end.
        $clock->advance('+1 minute');
        $handler = new CallableTaskHandler();
        $started = 0;

        foreach ($generator->getTasks() as $context => $task) {
            if (1 === $started) {
                break;
            }

            $handler->handle($task->getTask(), $context);
            ++$started;
        }

        unset($generator);
        $this->assertSame(['first'], $log->getArrayCopy());

        // The process replacing it has never seen a drained tick, so the run left unstarted is
        // due only if the first process wrote down where the schedule began before emitting
        // anything. Seeding that task from the now of this process would step over it.
        $log->exchangeArray([]);
        $this->tick($cache, $clock, [$first, $second]);

        $this->assertSame(['second'], $log->getArrayCopy());
    }

    public function testRecordsFitACacheLimitedToTheKeyLengthPsr16Guarantees(): void
    {
        $log = new ArrayObject();
        $task = static function () use ($log): void {
            $log[] = 'task';
        };

        $cache = new InMemoryCache();
        $cache->limitKeyLength(64);
        $clock = new TestClock();

        // A schedule name long enough that spelling it out in a key of its own per task would
        // pass what PSR-16 guarantees, while the name itself stays one a cache took before.
        $schedule = (new Schedule('daily_subscription_invoices'))
            ->stateful($cache)
            ->task(RecurringTask::cron('* * * * *', $task));

        (new Scheduler([$schedule], new CallableTaskHandler(), $clock))->tick();
        $clock->advance('+1 minute');
        (new Scheduler([$schedule], new CallableTaskHandler(), $clock))->tick();

        $this->assertSame(['task'], $log->getArrayCopy());
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
     * Emits the due runs one by one and stops before starting the third, the way a process killed
     * mid-tick leaves the two runs before it recorded and the ones behind it untouched.
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
                break;
            }

            $handler->handle($task->getTask(), $context);
            ++$started;
        }

        unset($generator);
    }

    /**
     * @param list<callable> $tasks
     */
    private function tick(InMemoryCache $cache, TestClock $clock, array $tasks): void
    {
        (new Scheduler([$this->schedule($cache, $tasks)], new CallableTaskHandler(), $clock))->tick();
    }

    /**
     * The key the record of one task is kept under, built the way the checkpoint builds it.
     */
    private function taskKey(callable $task, string $scheduleName = 'default'): string
    {
        return 'yii_schedule_checkpoint_'
            . hash('xxh3', $scheduleName)
            . '_'
            . hash('xxh3', RecurringTask::cron('* * * * *', $task)->getId());
    }

    /**
     * @param list<callable> $tasks
     */
    private function schedule(InMemoryCache $cache, array $tasks): Schedule
    {
        $schedule = (new Schedule())->stateful($cache);

        foreach ($tasks as $task) {
            $schedule->task(RecurringTask::cron('* * * * *', $task));
        }

        return $schedule;
    }
}
