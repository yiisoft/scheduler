<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Yiisoft\Queue\Message\GenericMessage;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Trigger\PeriodicTrigger;

final class RecurringTaskTest extends TestCase
{
    public function testUnparsableIntervalStringIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The interval string could not be parsed.');

        RecurringTask::every('garbage', 'app:noop');
    }

    public function testGeneratedIdIsWideEnoughToTellTasksApart(): void
    {
        // A checkpoint tells the runs it holds from the ones still to come by this identifier,
        // so the width is what keeps a task added to a schedule from being taken for another.
        $id = RecurringTask::cron('0 5 * * *', 'report:daily')->getId();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
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

    public function testOneExpressionReadInTwoTimezonesProducesTwoIds(): void
    {
        // Nine in the morning in Sydney is not nine in the morning in Skopje, so a schedule
        // holding both reports is holding two tasks.
        $sydney = RecurringTask::cron('0 9 * * *', 'report:daily', 'Australia/Sydney');
        $skopje = RecurringTask::cron('0 9 * * *', 'report:daily', 'Europe/Skopje');

        $this->assertNotSame($sydney->getId(), $skopje->getId());
        $this->assertCount(2, (new Schedule())->task($sydney, $skopje)->tasks());
    }

    public function testAnExpressionLeftToTheDefaultTimezoneKeepsOneId(): void
    {
        $first = RecurringTask::cron('0 9 * * *', 'report:daily');
        $second = RecurringTask::cron('0 9 * * *', 'report:daily', null);

        $this->assertSame($first->getId(), $second->getId());
    }

    public function testIntervalsCountedFromDifferentAnchorsProduceDifferentIds(): void
    {
        $january = new PeriodicTrigger(3600, new DateTimeImmutable('2026-01-01 00:00:00'));
        $june = new PeriodicTrigger(3600, new DateTimeImmutable('2026-06-01 00:00:00'));

        $this->assertNotSame(
            RecurringTask::trigger($january, 'app:task')->getId(),
            RecurringTask::trigger($june, 'app:task')->getId(),
        );
    }

    public function testAnIntervalThatStopsIsNotTheOneThatNeverStops(): void
    {
        $bounded = new PeriodicTrigger(3600, null, new DateTimeImmutable('2026-06-01 00:00:00'));
        $endless = new PeriodicTrigger(3600);

        $this->assertNotSame(
            RecurringTask::trigger($bounded, 'app:task')->getId(),
            RecurringTask::trigger($endless, 'app:task')->getId(),
        );
    }

    public function testMessagesOfOneTypeCarryingDifferentPayloadsProduceDifferentIds(): void
    {
        $first = RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('report.send', ['to' => 'a@example.com']));
        $second = RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('report.send', ['to' => 'b@example.com']));

        $this->assertNotSame($first->getId(), $second->getId());
    }

    public function testOnePayloadWrittenInTwoOrdersProducesOneId(): void
    {
        $first = RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('report.send', ['x' => 1, 'y' => [2, 3]]));
        $second = RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('report.send', ['y' => [2, 3], 'x' => 1]));

        $this->assertSame($first->getId(), $second->getId());
    }

    public function testAKeyAndItsValueCannotRunTogetherIntoOneDescription(): void
    {
        // Without something between the key and the value, "a1" holding 2 and "a" holding 12
        // would be written the same way and the two tasks would be one.
        $first = RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('report.send', ['a1' => 2]));
        $second = RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('report.send', ['a' => 12]));

        $this->assertNotSame($first->getId(), $second->getId());
    }

    public function testPayloadsCarryingOneValueUnderDifferentKeysProduceDifferentIds(): void
    {
        $region = RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('report.send', ['region' => 1]));
        $tenant = RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('report.send', ['tenant' => 1]));

        $this->assertNotSame($region->getId(), $tenant->getId());
    }

    public function testPayloadsThatDifferOnlyInWhereTheirNestingStartsProduceDifferentIds(): void
    {
        // Without the brackets around a nested payload, both of these would be written as one
        // flat run of keys and values.
        $outside = RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('report.send', ['a' => ['b' => 1], 'c' => 2]));
        $inside = RecurringTask::cron('*/5 * * * *', GenericMessage::fromPayload('report.send', ['a' => ['b' => 1, 'c' => 2]]));

        $this->assertNotSame($outside->getId(), $inside->getId());
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
