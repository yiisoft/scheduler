<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Trigger;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Trigger\CronExpressionTrigger;

use function count;
use function sprintf;
use function date_default_timezone_get;
use function date_default_timezone_set;

final class CronExpressionTriggerTest extends TestCase
{
    public function testNextRunIsStrictlyAfterLastRun(): void
    {
        $trigger = new CronExpressionTrigger('* * * * *');
        $lastRun = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        $next = $trigger->getNextRunDate($lastRun);

        $this->assertGreaterThan($lastRun, $next);
        $this->assertSame('2026-01-01 00:01:00', $next->format('Y-m-d H:i:s'));
    }

    public function testCronExpression(): void
    {
        $trigger = new CronExpressionTrigger('0 5 * * *');
        $lastRun = new DateTimeImmutable('2026-01-01 06:00:00+00:00');

        $next = $trigger->getNextRunDate($lastRun);

        $this->assertSame('2026-01-02 05:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function testTimezone(): void
    {
        $trigger = new CronExpressionTrigger('0 9 * * *', 'Europe/Skopje');
        $lastRun = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        $next = $trigger->getNextRunDate($lastRun);

        $this->assertSame('09:00', $next->setTimezone(new DateTimeZone('Europe/Skopje'))->format('H:i'));
    }

    public function testExpressionIsReadInTheDefaultTimezoneWhateverTheTimezoneOfTheLastRun(): void
    {
        $default = date_default_timezone_get();
        date_default_timezone_set('Europe/Moscow');

        try {
            $trigger = new CronExpressionTrigger('0 5 * * *');

            foreach (['2026-01-01 00:00:00+00:00', '2026-01-01 03:00:00+03:00'] as $lastRun) {
                $next = $trigger->getNextRunDate(new DateTimeImmutable($lastRun));

                $this->assertSame('2026-01-01T05:00:00+03:00', $next->format('c'));
            }
        } finally {
            date_default_timezone_set($default);
        }
    }

    public function testToString(): void
    {
        $this->assertSame('cron(0 5 * * *)', (string) new CronExpressionTrigger('0 5 * * *'));
    }

    /**
     * The property a range rests on: asked for a part of the sequence, the trigger answers with
     * the dates it would have answered with one at a time.
     */
    #[DataProvider('ranges')]
    public function testARangeHoldsExactlyTheDatesTheTriggerWouldGiveOneAtATime(
        CronExpressionTrigger $trigger,
        DateTimeImmutable $after,
        DateTimeImmutable $before,
    ): void {
        $oneAtATime = [];
        $cursor = $after;

        while (true) {
            $next = $trigger->getNextRunDate($cursor);

            if (null === $next || $next >= $before) {
                break;
            }

            $oneAtATime[] = $next;
            $cursor = $next;
        }

        $this->assertNotSame([], $oneAtATime);
        $this->assertEquals($oneAtATime, $trigger->getRunDates($after, $before, 400));
    }

    /**
     * @return iterable<string, array{CronExpressionTrigger, DateTimeImmutable, DateTimeImmutable}>
     */
    public static function ranges(): iterable
    {
        $utc = new DateTimeZone('UTC');
        $skopje = new DateTimeZone('Europe/Skopje');

        yield 'every hour' => [
            new CronExpressionTrigger('0 * * * *'),
            new DateTimeImmutable('2026-03-01 00:00:00', $utc),
            new DateTimeImmutable('2026-03-01 08:00:00', $utc),
        ];

        yield 'an hour that the spring change takes away' => [
            new CronExpressionTrigger('0 2 * * *', 'Europe/Skopje'),
            new DateTimeImmutable('2026-03-27 00:00:00', $skopje),
            new DateTimeImmutable('2026-04-01 00:00:00', $skopje),
        ];

        yield 'a day that only leap years have' => [
            new CronExpressionTrigger('0 0 29 2 *'),
            new DateTimeImmutable('2024-01-01 00:00:00', $utc),
            new DateTimeImmutable('2033-01-01 00:00:00', $utc),
        ];
    }

    public function testABatchContinuedFromItsLastDateCoversTheRangeExactlyOnce(): void
    {
        $trigger = new CronExpressionTrigger('0 * * * *');
        $after = new DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $before = new DateTimeImmutable('2026-01-01 06:00:00+00:00');

        $batched = [];
        $cursor = $after;

        while ([] !== $batch = $trigger->getRunDates($cursor, $before, 2)) {
            $batched = [...$batched, ...$batch];
            $cursor = $batch[count($batch) - 1];
        }

        $this->assertEquals($trigger->getRunDates($after, $before, 100), $batched);
    }

    public function testTheMinuteAskedAboutIsItselfAnAnswer(): void
    {
        $trigger = new CronExpressionTrigger('0 * * * *');
        $onTheHour = new DateTimeImmutable('2026-01-01 05:00:00+00:00');

        $this->assertEquals($onTheHour, $trigger->getNextRunDateAtOrAfter($onTheHour));
    }

    public function testAMinuteAlreadyUnderWayIsBehindTheDateAskedAbout(): void
    {
        // An expression names a minute, and the library answers with the start of it, so half a
        // minute past the run is past it: the answer is the next one, not the one just missed.
        $trigger = new CronExpressionTrigger('0 * * * *');

        $this->assertSame(
            '2026-01-01 06:00:00',
            $trigger
                ->getNextRunDateAtOrAfter(new DateTimeImmutable('2026-01-01 05:00:30+00:00'))
                ?->format('Y-m-d H:i:s'),
        );
    }

    public function testARangeNeverAnswersWithADateBeforeTheOneAskedAbout(): void
    {
        // Half an hour into a run is past it, and a change of the clock is where that is easiest
        // to get wrong: these are the days the clock moves in zones that move it by half an hour
        // and by a whole one.
        foreach (['Europe/Skopje', 'Australia/Lord_Howe', 'America/St_Johns'] as $zone) {
            $trigger = new CronExpressionTrigger('*/15 * * * *', $zone);
            $timezone = new DateTimeZone($zone);

            foreach (['2026-03-29', '2026-04-05', '2026-10-04', '2026-10-25'] as $day) {
                for ($hour = 0; $hour < 24; ++$hour) {
                    $date = new DateTimeImmutable(sprintf('%s %02d:30:00', $day, $hour), $timezone);
                    $next = $trigger->getNextRunDateAtOrAfter($date);

                    $this->assertNotNull($next);
                    $this->assertGreaterThanOrEqual($date, $next, sprintf('at %s', $date->format('c')));
                }
            }
        }
    }

    public function testARunAlreadyAccountedForIsNotGivenAgain(): void
    {
        $trigger = new CronExpressionTrigger('0 * * * *');
        $onTheHour = new DateTimeImmutable('2026-01-01 05:00:00+00:00');

        $this->assertSame(
            '2026-01-01 06:00:00',
            $trigger->getNextRunDateAtOrAfter($onTheHour, $onTheHour)?->format('Y-m-d H:i:s'),
        );
    }

    public function testAPhaseCarriesARangePastTheRunsAlreadyAccountedFor(): void
    {
        $trigger = new CronExpressionTrigger('0 * * * *');

        $dates = $trigger->getRunDates(
            new DateTimeImmutable('2026-01-01 00:00:00+00:00'),
            new DateTimeImmutable('2026-01-01 06:00:00+00:00'),
            10,
            new DateTimeImmutable('2026-01-01 03:00:00+00:00'),
        );

        $this->assertCount(2, $dates);
        $this->assertSame('2026-01-01 04:00:00', $dates[0]->format('Y-m-d H:i:s'));
    }

    public function testARangeThatEndsBeforeItStartsIsEmpty(): void
    {
        $trigger = new CronExpressionTrigger('0 * * * *');

        $this->assertSame([], $trigger->getRunDates(
            new DateTimeImmutable('2026-01-02 00:00:00+00:00'),
            new DateTimeImmutable('2026-01-01 00:00:00+00:00'),
            5,
        ));
    }

    public function testARangeHoldsNoMoreDatesThanAskedFor(): void
    {
        $trigger = new CronExpressionTrigger('* * * * *');

        $dates = $trigger->getRunDates(
            new DateTimeImmutable('2026-01-01 00:00:00+00:00'),
            new DateTimeImmutable('2026-01-02 00:00:00+00:00'),
            3,
        );

        $this->assertCount(3, $dates);
        $this->assertSame('2026-01-01 00:03:00', $dates[2]->format('Y-m-d H:i:s'));
    }

    public function testALimitOfNoneIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The limit must be greater than zero, "0" given.');

        (new CronExpressionTrigger('* * * * *'))->getRunDates(
            new DateTimeImmutable('2026-01-01 00:00:00+00:00'),
            new DateTimeImmutable('2026-01-02 00:00:00+00:00'),
            0,
        );
    }

    public function testARangeIsReadInTheTimezoneOfTheExpressionAndNotOfTheDate(): void
    {
        $trigger = new CronExpressionTrigger('0 9 * * *', 'Europe/Skopje');

        $dates = $trigger->getRunDates(
            new DateTimeImmutable('2026-01-01 00:00:00+00:00'),
            new DateTimeImmutable('2026-01-03 00:00:00+00:00'),
            10,
        );

        $this->assertCount(2, $dates);

        foreach ($dates as $date) {
            $this->assertSame('09:00', $date->setTimezone(new DateTimeZone('Europe/Skopje'))->format('H:i'));
        }
    }
}
