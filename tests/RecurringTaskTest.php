<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests;

use PHPUnit\Framework\TestCase;
use Yiisoft\Queue\Message\GenericMessage;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;

final class RecurringTaskTest extends TestCase
{
    public function testUnparsableIntervalStringIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The interval string could not be parsed.');

        RecurringTask::every('garbage', 'app:noop');
    }

    public function testIntervalsOfDifferentLengthProduceDifferentIds(): void
    {
        $monthly = RecurringTask::every('1 month', 'app:monthly');
        $bimonthly = RecurringTask::every('2 months', 'app:monthly');
        $yearly = RecurringTask::every('1 year', 'app:monthly');

        $this->assertNotSame($monthly->getId(), $bimonthly->getId());
        $this->assertNotSame($monthly->getId(), $yearly->getId());
        $this->assertNotSame($bimonthly->getId(), $yearly->getId());
    }

    public function testMicrosecondIntervalDoesNotCollideWithAMonth(): void
    {
        $monthly = RecurringTask::every('1 month', 'app:task');
        $sub = RecurringTask::every('500 microseconds', 'app:task');

        $this->assertNotSame($monthly->getId(), $sub->getId());
    }

    public function testMessagesOfDifferentTypesProduceDifferentIds(): void
    {
        $metrics = RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('metrics.collect', []));
        $cache = RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('cache.warm', []));

        $this->assertNotSame($metrics->getId(), $cache->getId());
    }

    public function testWithIdReplacesTheGeneratedId(): void
    {
        $task = RecurringTask::every(60, 'app:task');
        $named = $task->withId('nightly');

        $this->assertSame('nightly', $named->getId());
        $this->assertNotSame('nightly', $task->getId());
        $this->assertSame($task->getTrigger(), $named->getTrigger());
        $this->assertSame($task->getTask(), $named->getTask());
    }

    public function testEmptyIdIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The task id must not be empty.');

        RecurringTask::every(60, 'app:task')->withId('');
    }

    public function testTasksAlikeInEverythingButTheirIdShareASchedule(): void
    {
        $schedule = new Schedule();

        foreach (['first', 'second'] as $tenant) {
            $schedule->task(RecurringTask::every(60, static fn(): string => $tenant)->withId('sync-' . $tenant));
        }

        $this->assertCount(2, $schedule->tasks());
    }

    public function testDuplicateIdPointsAtWithId(): void
    {
        $schedule = new Schedule();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('RecurringTask::withId()');

        foreach (['first', 'second'] as $tenant) {
            $schedule->task(RecurringTask::every(60, static fn(): string => $tenant));
        }
    }
}
