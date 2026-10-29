<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\CronBuilder;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\RecurringTask;

final class CronBuilderTest extends TestCase
{
    public static function expressionProvider(): iterable
    {
        yield 'every minute' => ['* * * * *', RecurringTask::everyMinute(...)];
        yield 'every five minutes' => ['*/5 * * * *', RecurringTask::everyFiveMinutes(...)];
        yield 'every ten minutes' => ['*/10 * * * *', RecurringTask::everyTenMinutes(...)];
        yield 'every fifteen minutes' => ['*/15 * * * *', RecurringTask::everyFifteenMinutes(...)];
        yield 'every thirty minutes' => ['*/30 * * * *', RecurringTask::everyThirtyMinutes(...)];
        yield 'every three minutes' => ['*/3 * * * *', static fn() => RecurringTask::everyMinutes(3)];
        yield 'hourly' => ['0 * * * *', RecurringTask::hourly(...)];
        yield 'hourly at minutes' => ['15,45 * * * *', static fn() => RecurringTask::hourly()->atMinutes(15, 45)];
        yield 'every six hours' => ['0 */6 * * *', static fn() => RecurringTask::everyHours(6)];
        yield 'daily' => ['0 0 * * *', RecurringTask::daily(...)];
        yield 'daily at' => ['0 5 * * *', static fn() => RecurringTask::daily()->at('05:00')];
        yield 'daily at a single-digit hour' => ['30 7 * * *', static fn() => RecurringTask::daily()->at('7:30')];
        yield 'twice a day' => ['0 1,13 * * *', static fn() => RecurringTask::daily()->atHours(1, 13)];
        yield 'weekdays' => ['0 8 * * 1-5', static fn() => RecurringTask::daily()->weekdays()->at('08:00')];
        yield 'weekends' => ['0 0 * * 0,6', static fn() => RecurringTask::daily()->weekends()];
        yield 'weekly' => ['0 0 * * 1', RecurringTask::weekly(...)];
        yield 'weekly on a named day' => ['30 9 * * 1', static fn() => RecurringTask::weekly()->on('monday')->at('09:30')];
        yield 'two named days' => ['0 0 * * 2,4', static fn() => RecurringTask::daily()->on('Tuesday', 'THURSDAY')];
        yield 'sunday as seven' => ['0 0 * * 0', static fn() => RecurringTask::daily()->on(7)];
        yield 'monthly' => ['0 0 1 * *', RecurringTask::monthly(...)];
        yield 'twice a month' => ['0 3 1,15 * *', static fn() => RecurringTask::monthly()->onDays(1, 15)->at('03:00')];
        yield 'last day of the month' => ['0 23 L * *', static fn() => RecurringTask::monthly()->onLastDayOfMonth()->at('23:00')];
        yield 'quarterly' => ['0 0 1 1,4,7,10 *', RecurringTask::quarterly(...)];
        yield 'yearly' => ['0 0 1 1 *', RecurringTask::yearly(...)];
        yield 'in months' => ['0 0 * 6-8 *', static fn() => RecurringTask::daily()->inMonths(6, 7, 8)];
        yield 'between' => ['*/5 8-16 * * *', static fn() => RecurringTask::everyFiveMinutes()->between('08:00', '17:00')];
        yield 'between across midnight' => ['* 0,1,22,23 * * *', static fn() => RecurringTask::everyMinute()->between('22:00', '02:00')];
        yield 'unless between' => ['*/15 6-21 * * *', static fn() => RecurringTask::everyFifteenMinutes()->unlessBetween('22:00', '06:00')];
        yield 'from an existing expression' => ['0 4 * * 3', static fn() => (new CronBuilder('0 4 * * *'))->on('wednesday')];
        yield 'from an expression with room around it' => ['0 4 * * *', static fn() => new CronBuilder("  0 4  *\t* *  ")];
        yield 'an hour named twice' => ['0 1,13 * * *', static fn() => RecurringTask::daily()->atHours(13, 1, 13)];
        yield 'a window narrowing an interval' => ['0 12 * * *', static fn() => RecurringTask::everyHours(6)->between('08:00', '17:00')];
        yield 'a window keeping the only hour there is' => ['0 5 * * *', static fn() => RecurringTask::daily()->at('05:00')->between('00:00', '12:00')];
        yield 'a window narrowing a stepped field' => ['0 8,12,16,20 * * *', static fn() => RecurringTask::everyHours(4)->between('08:00', '22:00')];
    }

    public static function rejectedProvider(): iterable
    {
        yield 'an interval not dividing an hour' => [
            'An interval of 7 minutes does not divide an hour evenly',
            static fn() => RecurringTask::everyMinutes(7),
        ];
        yield 'an interval not dividing a day' => [
            'An interval of 5 hours does not divide a day evenly',
            static fn() => RecurringTask::everyHours(5),
        ];
        yield 'a time of day on a chain repeating within the hour' => [
            'the minute field is "*/5", so at() would drop runs without saying so',
            static fn() => RecurringTask::everyFiveMinutes()->at('05:00'),
        ];
        yield 'a time of day on a chain repeating within the day' => [
            'the hour field is "*", so at() would drop runs without saying so',
            static fn() => RecurringTask::hourly()->at('05:00'),
        ];
        yield 'a time of day on a chain already given its hours' => [
            'the hour field is "1,13", so at() would drop runs without saying so',
            static fn() => RecurringTask::daily()->atHours(1, 13)->at('05:00'),
        ];
        yield 'a window sharing no hour with the expression' => [
            "The expression runs at 5 o'clock, and the window from 08:00 to 17:00 covers none of them",
            static fn() => RecurringTask::daily()->at('05:00')->between('08:00', '17:00'),
        ];
        yield 'a window covering every hour of the expression' => [
            "The expression runs at 5 o'clock, and the window from 22:00 to 06:00 covers all of them",
            static fn() => RecurringTask::daily()->at('05:00')->unlessBetween('22:00', '06:00'),
        ];
        yield 'an hour field counting backwards' => [
            'The hour field "16-8" counts backwards',
            static fn() => (new CronBuilder('0 16-8 * * *'))->between('08:00', '17:00'),
        ];
        yield 'a day of the week on top of a day of the month' => [
            'The day of the month is already set to "1", and cron runs a task on a day matching either day field',
            static fn() => RecurringTask::monthly()->on('monday'),
        ];
        yield 'a day of the month on top of a day of the week' => [
            'The day of the week is already set to "1", and cron runs a task on a day matching either day field',
            static fn() => RecurringTask::weekly()->onLastDayOfMonth(),
        ];
        yield 'a timezone that is not one' => [
            '"Nowhere/Nope" is not a timezone',
            static fn() => RecurringTask::daily()->timezone('Nowhere/Nope'),
        ];
        yield 'a time that is not one' => [
            'A time written as "HH:MM" is expected, got "noon"',
            static fn() => RecurringTask::daily()->at('noon'),
        ];
        yield 'an hour past the end of the day' => [
            'The time "25:00" is not a time of day',
            static fn() => RecurringTask::daily()->at('25:00'),
        ];
        yield 'a window starting part way through an hour' => [
            'A window of whole hours is expected',
            static fn() => RecurringTask::everyFiveMinutes()->between('08:30', '17:00'),
        ];
        yield 'an empty window' => [
            'The window from 08:00 to 08:00 is empty',
            static fn() => RecurringTask::everyFiveMinutes()->between('08:00', '08:00'),
        ];
        yield 'a day of the week that is not one' => [
            '"funday" is not a day of the week',
            static fn() => RecurringTask::daily()->on('funday'),
        ];
        yield 'a numbered day out of range' => [
            'A day of the week is 0 to 7, got 9',
            static fn() => RecurringTask::daily()->on(9),
        ];
        yield 'no day of the week at all' => [
            'At least one day of the week is expected',
            static fn() => RecurringTask::daily()->on(),
        ];
        yield 'a day of the month out of range' => [
            'A day of the month is 1 to 31, got 0',
            static fn() => RecurringTask::monthly()->onDays(0),
        ];
        yield 'no hour at all' => [
            'At least one hour is expected',
            static fn() => RecurringTask::daily()->atHours(),
        ];
        yield 'too few fields' => [
            'A cron expression of five fields is expected, got "* * *"',
            static fn() => new CronBuilder('* * *'),
        ];
        yield 'five fields that are not a cron expression' => [
            'The cron expression "a b c d e" is not valid',
            static fn() => new CronBuilder('a b c d e'),
        ];
    }

    #[DataProvider('expressionProvider')]
    public function testExpression(string $expected, Closure $build): void
    {
        $builder = $build();

        $this->assertInstanceOf(CronBuilder::class, $builder);
        $this->assertSame($expected, $builder->getExpression());
        $this->assertSame($expected, (string) $builder);
    }

    #[DataProvider('rejectedProvider')]
    public function testRejected(string $message, Closure $build): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        $build();
    }

    public function testEachStepLeavesTheBuilderItWasCalledOnAlone(): void
    {
        $daily = RecurringTask::daily();

        $morning = $daily->at('08:00');
        $evening = $daily->at('20:00');

        $this->assertSame('0 0 * * *', $daily->getExpression());
        $this->assertSame('0 8 * * *', $morning->getExpression());
        $this->assertSame('0 20 * * *', $evening->getExpression());
    }

    public function testTheTaskIsTheOneTheSameExpressionWrittenByHandGives(): void
    {
        $built = RecurringTask::daily()->at('05:00')->run('report:daily');
        $byHand = RecurringTask::cron('0 5 * * *', 'report:daily');

        $this->assertSame($byHand->getId(), $built->getId());
        $this->assertSame('report:daily', $built->getTask());
        $this->assertSame('cron(0 5 * * *)', (string) $built->getTrigger());
    }

    public function testTheTriggerFiresAtTheTimesBuilt(): void
    {
        $trigger = RecurringTask::everyFiveMinutes()->between('08:00', '17:00')->toTrigger();

        $next = $trigger->getNextRunDate(new DateTimeImmutable('2026-01-05 16:55:00+00:00'));

        $this->assertNotNull($next);
        $this->assertSame('2026-01-06 08:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function testTheWindowAcrossMidnightCoversBothSidesOfIt(): void
    {
        $trigger = RecurringTask::hourly()->between('22:00', '02:00')->toTrigger();

        $next = $trigger->getNextRunDate(new DateTimeImmutable('2026-01-05 23:30:00+00:00'));

        $this->assertNotNull($next);
        $this->assertSame('2026-01-06 00:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function testTheExpressionIsReadInTheGivenTimezone(): void
    {
        $trigger = RecurringTask::daily()->at('09:00')->timezone('Europe/Skopje')->toTrigger();

        $next = $trigger->getNextRunDate(new DateTimeImmutable('2026-01-01 00:00:00+00:00'));

        $this->assertNotNull($next);
        $this->assertSame('09:00', $next->setTimezone(new DateTimeZone('Europe/Skopje'))->format('H:i'));
    }

    public function testTheTimezoneSurvivesTheStepsAfterItAndReachesTheTask(): void
    {
        $task = (new CronBuilder('0 0 * * *', new DateTimeZone('Europe/Skopje')))
            ->at('09:00')
            ->run('report:daily');

        $next = $task->getTrigger()->getNextRunDate(new DateTimeImmutable('2026-01-01 00:00:00+00:00'));

        $this->assertSame('cron(0 9 * * *)', (string) $task->getTrigger());
        $this->assertNotNull($next);
        $this->assertSame('09:00', $next->setTimezone(new DateTimeZone('Europe/Skopje'))->format('H:i'));
    }
}
