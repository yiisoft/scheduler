<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests;

use ArrayObject;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Generator\ProcessOwner;
use Yiisoft\Scheduler\Generator\ScheduleOwnership;
use Yiisoft\Scheduler\Generator\TaskGenerator;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\Tests\Support\InMemoryCache;
use Yiisoft\Scheduler\TaskContext;
use Yiisoft\Scheduler\Tests\Support\TestClock;

use function hash;
use function is_array;

/**
 * What a scheduler does about a schedule being one process's to plan.
 *
 * Nothing is planned or handed out before the schedule has been taken on, and a process that is
 * done with it hands it back for the next one.
 */
final class ScheduleOwnerTest extends TestCase
{
    public function testAScheduleAnotherProcessIsPlanningRunsNothingHere(): void
    {
        $log = self::log();
        $cache = new InMemoryCache();
        $clock = new TestClock();

        $scheduler = $this->scheduler($cache, $clock, $log);
        $scheduler->tick();
        $clock->advance('+1 minute');

        // The run due now is due to whoever is planning the schedule, which is not this process.
        $this->plantOwner($cache, 'default', 'elsewhere/1', $this->at('+1 minute'));

        $this->assertSame(0, $scheduler->tick());
        $this->assertSame([], $log->getArrayCopy());
        $this->assertNull($scheduler->nextRunDate());
    }

    public function testATickHandsTheScheduleBackWhenItIsOver(): void
    {
        $log = self::log();
        $cache = new InMemoryCache();
        $clock = new TestClock();

        $scheduler = $this->scheduler($cache, $clock, $log);
        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertSame(['task'], $log->getArrayCopy());
        $this->assertNull($cache->get(ScheduleOwnership::keyFor('default')));
    }

    public function testALoopKeepsTheScheduleUntilItEnds(): void
    {
        $log = self::log();
        $cache = new InMemoryCache();
        $clock = new TestClock();
        /** @var Scheduler|null $scheduler */
        $scheduler = null;

        $schedule = (new Schedule())
            ->stateful($cache)
            ->task(RecurringTask::cron('* * * * *', static function () use ($cache, $log, &$scheduler): void {
                /** @var mixed $record */
                $record = $cache->get(ScheduleOwnership::keyFor('default'));
                $log[] = is_array($record) && ProcessOwner::current() === ($record['owner'] ?? null)
                    ? 'held'
                    : 'free';

                $scheduler?->stop();
            }));

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);
        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->run(0.01, false);

        $this->assertSame(['held'], $log->getArrayCopy(), 'the loop holds the schedule while it runs');
        $this->assertNull($cache->get(ScheduleOwnership::keyFor('default')), 'handed back once the loop ended');
    }

    /**
     * A process whose tasks were replaced under it plans nothing more: what it worked out came
     * from a schedule the records no longer belong to.
     */
    public function testAPlannerWhoseTasksWereReplacedPlansNothingMore(): void
    {
        $log = self::log();
        $cache = new InMemoryCache();
        $clock = new TestClock();

        $schedule = (new Schedule())
            ->stateful($cache)
            ->task(RecurringTask::cron('* * * * *', static function () use ($log): void {
                $log[] = 'task';
            }));

        $generator = new TaskGenerator($schedule, $clock);

        $this->drain($generator);
        $clock->advance('+1 minute');

        $this->assertNotSame([], $this->drain($generator), 'the schedule was taken on');
        $this->assertFalse($generator->isFenced());

        // What a replacement planning other tasks leaves behind.
        $this->plantOwner($cache, 'default', 'elsewhere/1', $this->at('+2 minutes'), 'rev-of-another-schedule');

        $clock->advance('+1 minute');

        $this->assertSame([], $this->drain($generator));
        $this->assertTrue($generator->isFenced());
        $this->assertNull($generator->getNextRunDate(), 'nothing is due to a planner that was replaced');
    }

    /**
     * Taking a schedule over says nothing about the runs the process before it had started.
     * Those are the checkpoint's to hand back, under the revision they were claimed with.
     */
    public function testAChangeOfOwnerLeavesTheRecordsOfTheRunsAlone(): void
    {
        $log = self::log();
        $cache = new InMemoryCache();
        $clock = new TestClock();

        $task = static function () use ($log): void {
            $log[] = 'task';
        };

        $schedule = (new Schedule())->stateful($cache)->task(RecurringTask::cron('* * * * *', $task));
        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick();

        $key = $this->taskKey($task);
        /** @var mixed $before */
        $before = $cache->get($key);

        $this->assertNotNull($before);

        $taken = new ScheduleOwnership('default', $cache);
        $taken->acquire('rev-of-another-schedule', $this->at('+2 minutes'), $schedule->getReplaceOwnerAfter());

        $this->assertSame($before, $cache->get($key));
    }

    /**
     * @param ArrayObject<int, string> $log
     */
    private function scheduler(InMemoryCache $cache, TestClock $clock, ArrayObject $log): Scheduler
    {
        $schedule = (new Schedule())
            ->stateful($cache)
            ->task(RecurringTask::cron('* * * * *', static function () use ($log): void {
                $log[] = 'task';
            }));

        return new Scheduler([$schedule], new CallableTaskHandler(), $clock);
    }

    /**
     * The runs a generator hands out, which claims every one of them.
     *
     * @return list<TaskContext>
     */
    private function drain(TaskGenerator $generator): array
    {
        $due = [];

        foreach ($generator->getTasks() as $context => $_) {
            $due[] = $context;
        }

        return $due;
    }

    /**
     * A record of what the tasks of a test did, in the order they did it.
     *
     * @return ArrayObject<int, string>
     */
    private static function log(): ArrayObject
    {
        return new ArrayObject();
    }

    private function plantOwner(
        InMemoryCache $cache,
        string $schedule,
        string $owner,
        DateTimeImmutable $seen,
        string $revision = 'rev-1',
    ): void {
        $cache->set(ScheduleOwnership::keyFor($schedule), [
            'owner' => $owner,
            'revision' => $revision,
            'since' => $seen->format('U.u'),
            'seen' => $seen->format('U.u'),
        ]);
    }

    /** The key the record of one task is kept under, built the way the checkpoint builds it. */
    private function taskKey(callable $task, string $scheduleName = 'default'): string
    {
        return 'yii_schedule_checkpoint_'
            . hash('xxh3', $scheduleName)
            . '_'
            . hash('xxh3', RecurringTask::cron('* * * * *', $task)->getId());
    }

    private function at(string $modifier = 'now'): DateTimeImmutable
    {
        $base = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        return 'now' === $modifier ? $base : $base->modify($modifier);
    }
}
