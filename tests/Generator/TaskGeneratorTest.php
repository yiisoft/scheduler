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

        // The two runs the checkpoint already holds are re-discovered and skipped; only the one
        // left unstarted is recorded, so the shared state is written once rather than three times.
        $this->assertSame(1, $cache->writes() - $writes);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedCheckpoints(): iterable
    {
        yield 'not an array' => ['garbage'];
        yield 'time is missing' => [['tasks' => []]];
        yield 'time is not a string' => [['time' => 1767225660, 'tasks' => []]];
        yield 'task list is missing' => [['time' => '1767225660.000000']];
        yield 'task list is not an array' => [['time' => '1767225660.000000', 'tasks' => 'first']];
        yield 'task list holds something other than an id' => [['time' => '1767225660.000000', 'tasks' => [42]]];
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
