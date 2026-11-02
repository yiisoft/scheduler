<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Trigger;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Trigger\PeriodicTrigger;

use function array_slice;
use function count;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function sprintf;

final class PeriodicTriggerTest extends TestCase
{
    public function testSecondsInterval(): void
    {
        $trigger = new PeriodicTrigger(300);
        $lastRun = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        $this->assertSame('2026-01-01 00:05:00', $trigger->getNextRunDate($lastRun)->format('Y-m-d H:i:s'));
    }

    public function testSecondsIntervalStaysAnchored(): void
    {
        $trigger = new PeriodicTrigger(300, new DateTimeImmutable('2026-01-01 00:00:00+00:00'));

        // 7 minutes after the anchor: next run is at 10, not at 12.
        $lastRun = new DateTimeImmutable('2026-01-01 00:07:00+00:00');

        $this->assertSame('2026-01-01 00:10:00', $trigger->getNextRunDate($lastRun)->format('Y-m-d H:i:s'));
    }

    public function testBeforeAnchorRunsAtAnchor(): void
    {
        $trigger = new PeriodicTrigger(300, new DateTimeImmutable('2026-01-01 12:00:00+00:00'));
        $lastRun = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        $this->assertSame('2026-01-01 12:00:00', $trigger->getNextRunDate($lastRun)->format('Y-m-d H:i:s'));
    }

    public function testUntilExhaustsTheTrigger(): void
    {
        $until = new DateTimeImmutable('2026-01-01 01:00:00+00:00');
        $trigger = new PeriodicTrigger(300, until: $until);

        $this->assertNotNull($trigger->getNextRunDate(new DateTimeImmutable('2026-01-01 00:00:00+00:00')));
        $this->assertNotNull($trigger->getNextRunDate(new DateTimeImmutable('2026-01-01 00:56:00+00:00')));
        $this->assertNull($trigger->getNextRunDate(new DateTimeImmutable('2026-01-01 01:00:00+00:00')));
    }

    public function testWithoutAnAnchorTheRunsDoNotDependOnTheDateAskedAbout(): void
    {
        $trigger = new PeriodicTrigger(600);

        // Asked at any moment of the same ten minutes, the answer is the same wall-clock run.
        foreach (['00:00:00', '00:03:27', '00:09:59.999999'] as $time) {
            $lastRun = new DateTimeImmutable('2026-01-01 ' . $time . '+00:00');

            $this->assertSame('2026-01-01 00:10:00', $trigger->getNextRunDate($lastRun)->format('Y-m-d H:i:s'));
        }
    }

    public function testDateIntervalWithoutAnAnchorDoesNotDependOnTheDateAskedAbout(): void
    {
        $trigger = new PeriodicTrigger(new DateInterval('PT10M'));
        $lastRun = new DateTimeImmutable('2026-01-01 00:03:27+00:00');

        $this->assertSame(
            '2026-01-01 00:10:00',
            $trigger->getNextRunDate($lastRun)->setTimezone($lastRun->getTimezone())->format('Y-m-d H:i:s'),
        );
    }

    public function testDateInterval(): void
    {
        $trigger = new PeriodicTrigger(new DateInterval('P1D'));
        $lastRun = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        $this->assertSame('2026-01-02 00:00:00', $trigger->getNextRunDate($lastRun)->format('Y-m-d H:i:s'));
    }

    public function testDateIntervalDescriptionDistinguishesMonthsFromYears(): void
    {
        $this->assertSame('every(P1M)', (string) new PeriodicTrigger(new DateInterval('P1M')));
        $this->assertSame('every(P2M)', (string) new PeriodicTrigger(new DateInterval('P2M')));
        $this->assertSame('every(P1Y)', (string) new PeriodicTrigger(new DateInterval('P1Y')));
    }

    public function testDateIntervalDescriptionKeepsSubSecondPrecision(): void
    {
        $interval = DateInterval::createFromDateString('500 microseconds');

        $this->assertSame('every(PT0.0005S)', (string) new PeriodicTrigger($interval));
    }

    public function testDateIntervalDescriptionOfAZeroInterval(): void
    {
        $this->assertSame('every(P0D)', (string) new PeriodicTrigger(new DateInterval('PT0S')));
    }

    public function testShortDateIntervalFarPastTheAnchor(): void
    {
        $from = new DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $trigger = new PeriodicTrigger(new DateInterval('PT1S'), $from);

        // Four hours past the anchor is more than 10000 one-second steps away.
        $lastRun = new DateTimeImmutable('2026-01-01 04:00:00+00:00');

        $this->assertSame('2026-01-01 04:00:01', $trigger->getNextRunDate($lastRun)->format('Y-m-d H:i:s'));
    }

    public function testCalendarDateIntervalStaysAnchored(): void
    {
        $from = new DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $trigger = new PeriodicTrigger(new DateInterval('P1M'), $from);

        $lastRun = new DateTimeImmutable('2026-06-15 00:00:00+00:00');

        $this->assertSame('2026-07-01 00:00:00', $trigger->getNextRunDate($lastRun)->format('Y-m-d H:i:s'));
    }

    public function testDateIntervalBeforeTheAnchorRunsAtTheFirstMultiple(): void
    {
        $from = new DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $trigger = new PeriodicTrigger(new DateInterval('P1M'), $from);

        $lastRun = new DateTimeImmutable('2025-12-01 00:00:00+00:00');

        $this->assertSame('2026-01-01 00:00:00', $trigger->getNextRunDate($lastRun)->format('Y-m-d H:i:s'));
    }

    public function testNonAdvancingDateIntervalIsRejected(): void
    {
        $from = new DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $trigger = new PeriodicTrigger(new DateInterval('PT0S'), $from);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The interval does not advance the date');

        $trigger->getNextRunDate(new DateTimeImmutable('2026-01-01 01:00:00+00:00'));
    }

    public function testIntervalOfTimeOnlyRunsThroughTheHourRepeatedWhenTheClocksGoBack(): void
    {
        $this->inTimezone('Europe/Berlin', function (): void {
            $triggers = [
                new PeriodicTrigger(new DateInterval('PT30M')),
                new PeriodicTrigger(new DateInterval('PT30M'), new DateTimeImmutable('2026-01-01 00:00:00')),
                new PeriodicTrigger(1800),
            ];

            foreach ($triggers as $trigger) {
                $run = new DateTimeImmutable('2026-10-25 00:59:00');
                $runs = [];

                for ($i = 0; $i < 6; ++$i) {
                    $run = $trigger->getNextRunDate($run);
                    $runs[] = $run->setTimezone(new DateTimeZone('Europe/Berlin'))->format('H:i T');
                }

                $this->assertSame(
                    ['01:00 CEST', '01:30 CEST', '02:00 CEST', '02:30 CEST', '02:00 CET', '02:30 CET'],
                    $runs,
                );
            }
        });
    }

    public function testRunsKeepTheFractionOfASecondOfTheAnchor(): void
    {
        $from = new DateTimeImmutable('2026-01-01 00:00:00.500000+00:00');

        foreach ([new PeriodicTrigger(10, $from), new PeriodicTrigger(new DateInterval('PT10S'), $from)] as $trigger) {
            $run = new DateTimeImmutable('2025-12-31 23:59:59+00:00');
            $runs = [];

            for ($i = 0; $i < 3; ++$i) {
                $run = $trigger->getNextRunDate($run);
                $runs[] = $run->format('H:i:s.u');
            }

            $this->assertSame(['00:00:00.500000', '00:00:10.500000', '00:00:20.500000'], $runs);

            // Asked between two runs, before and after the fraction within the second.
            foreach (['00:00:10.200000' => '00:00:10.500000', '00:00:10.700000' => '00:00:20.500000'] as $lastRun => $expected) {
                $next = $trigger->getNextRunDate(new DateTimeImmutable('2026-01-01 ' . $lastRun . '+00:00'));

                $this->assertSame($expected, $next->format('H:i:s.u'));
            }
        }
    }

    public function testDefaultAnchorIsReadInTheDefaultTimezoneOfTheMomentTheTriggerIsAsked(): void
    {
        // Declared before the application has set its timezone, as a configuration file may be.
        $trigger = new PeriodicTrigger(new DateInterval('P1D'));

        $this->inTimezone('Europe/Moscow', function () use ($trigger): void {
            $next = $trigger->getNextRunDate(new DateTimeImmutable('2026-01-14 09:03:00'));

            $this->assertSame('2026-01-15T00:00:00+03:00', $next->format('c'));
        });
    }

    public function testIntervalOfDaysKeepsItsLocalTimeAcrossAClockChange(): void
    {
        $this->inTimezone('Europe/Berlin', function (): void {
            $trigger = new PeriodicTrigger(new DateInterval('P1D'));

            foreach (['2026-01-14 09:03:00' => '2026-01-15T00:00:00+01:00', '2026-07-15 09:03:00' => '2026-07-16T00:00:00+02:00'] as $lastRun => $expected) {
                $this->assertSame($expected, $trigger->getNextRunDate(new DateTimeImmutable($lastRun))->format('c'));
            }
        });
    }

    public function testWeeksAreCountedFromMondaysWithoutAnAnchor(): void
    {
        $trigger = new PeriodicTrigger(new DateInterval('P1W'));

        $next = $trigger->getNextRunDate(new DateTimeImmutable('2026-01-14 09:03:00+00:00'));

        $this->assertSame('Monday 2026-01-19 00:00:00', $next->format('l Y-m-d H:i:s'));
    }

    public function testSecondsAndTheSameIntervalAsADateIntervalAgreeWithoutAnAnchor(): void
    {
        // A timezone whose offset in 1970 was not the one it has now.
        $this->inTimezone('Asia/Kathmandu', function (): void {
            $lastRun = new DateTimeImmutable('2026-01-14 09:03:00');

            foreach ([new PeriodicTrigger(3600), new PeriodicTrigger(new DateInterval('PT1H'))] as $trigger) {
                $this->assertSame('2026-01-14T10:00:00+05:45', $trigger->getNextRunDate($lastRun)->format('c'));
            }
        });
    }

    #[DataProvider('relativeFormats')]
    public function testIntervalOfARelativeFormatIsRejected(string $format): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('use a cron expression for calendar-based schedules');

        new PeriodicTrigger(DateInterval::createFromDateString($format));
    }

    public static function relativeFormats(): array
    {
        return [
            'weekday' => ['1 weekday'],
            'weekdays' => ['3 weekdays'],
            'day of a month' => ['first monday of next month'],
        ];
    }

    #[DataProvider('plainFormats')]
    public function testIntervalOfAPlainAmountOfTimeIsAccepted(string $format): void
    {
        $trigger = new PeriodicTrigger(DateInterval::createFromDateString($format));
        $lastRun = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        $this->assertGreaterThan($lastRun, $trigger->getNextRunDate($lastRun));
    }

    public function testSubSecondIntervalWithoutAnAnchor(): void
    {
        $trigger = new PeriodicTrigger(DateInterval::createFromDateString('500 microseconds'));
        $lastRun = new DateTimeImmutable('2026-01-01 00:00:00.000100+00:00');

        $this->assertSame('2026-01-01 00:00:00.000500', $trigger->getNextRunDate($lastRun)->format('Y-m-d H:i:s.u'));
    }

    public static function plainFormats(): array
    {
        return [
            'minutes' => ['90 minutes'],
            'week' => ['1 week'],
            'month' => ['1 month'],
            'mixed' => ['1 day 12 hours'],
            'sub-second' => ['500 microseconds'],
        ];
    }

    /**
     * The one property the whole range API rests on: asked for a part of the sequence, a trigger
     * answers with the dates it would have answered with one at a time.
     */
    #[DataProvider('ranges')]
    public function testARangeHoldsExactlyTheDatesTheTriggerWouldGiveOneAtATime(
        PeriodicTrigger $trigger,
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
        $this->assertEquals($oneAtATime, $trigger->getRunDates($after, $before, 200));
    }

    /**
     * @return iterable<string, array{PeriodicTrigger, DateTimeImmutable, DateTimeImmutable}>
     */
    public static function ranges(): iterable
    {
        $utc = new DateTimeZone('UTC');
        $skopje = new DateTimeZone('Europe/Skopje');

        yield 'an interval of seconds' => [
            new PeriodicTrigger(3600),
            new DateTimeImmutable('2026-03-01 00:00:00', $utc),
            new DateTimeImmutable('2026-03-01 06:00:00', $utc),
        ];

        yield 'a day across the spring change' => [
            new PeriodicTrigger(new DateInterval('P1D'), new DateTimeImmutable('2026-03-25 00:00:00', $skopje)),
            new DateTimeImmutable('2026-03-26 00:00:00', $skopje),
            new DateTimeImmutable('2026-04-02 00:00:00', $skopje),
        ];

        yield 'a month anchored to the end of one' => [
            new PeriodicTrigger(new DateInterval('P1M'), new DateTimeImmutable('2026-01-31 00:00:00', $utc)),
            new DateTimeImmutable('2026-01-31 00:00:00', $utc),
            new DateTimeImmutable('2026-07-01 00:00:00', $utc),
        ];

        yield 'a year anchored to a leap day' => [
            new PeriodicTrigger(new DateInterval('P1Y'), new DateTimeImmutable('2024-02-29 00:00:00', $utc)),
            new DateTimeImmutable('2024-02-29 00:00:00', $utc),
            new DateTimeImmutable('2029-01-01 00:00:00', $utc),
        ];

        yield 'an interval shorter than a second' => [
            new PeriodicTrigger(
                DateInterval::createFromDateString('500 microseconds'),
                new DateTimeImmutable('2026-01-01 00:00:00', $utc),
            ),
            new DateTimeImmutable('2026-01-01 00:00:00', $utc),
            new DateTimeImmutable('2026-01-01 00:00:00.003000', $utc),
        ];
    }

    public function testTheFirstInstantAfterAClockChangeIsAnswerableAtOrAfter(): void
    {
        // A microsecond before 03:00 on this day is 01:59:59.999999, an hour the clock skipped,
        // so asking the date for it lands an hour ahead instead of a microsecond behind. Counted
        // the wrong way, the four runs of that hour are silently gone.
        $trigger = new PeriodicTrigger(900);
        $afterTheChange = new DateTimeImmutable('2026-03-29 03:00:00', new DateTimeZone('Europe/Skopje'));

        $this->assertEquals($afterTheChange, $trigger->getNextRunDateAtOrAfter($afterTheChange));
    }

    public function testARangeNeverAnswersWithADateBeforeTheOneAskedAbout(): void
    {
        $trigger = new PeriodicTrigger(900);
        $skopje = new DateTimeZone('Europe/Skopje');

        foreach (['2026-03-29', '2026-10-25'] as $day) {
            for ($hour = 0; $hour < 24; ++$hour) {
                $date = new DateTimeImmutable(sprintf('%s %02d:00:00', $day, $hour), $skopje);
                $next = $trigger->getNextRunDateAtOrAfter($date);

                $this->assertNotNull($next);
                $this->assertGreaterThanOrEqual($date, $next, sprintf('at %s', $date->format('c')));
            }
        }
    }

    public function testARangeWritesItsDatesTheWayTheTriggerWritesThemOneAtATime(): void
    {
        // Instants alone are not enough: a date carries the timezone it is written in, and a
        // schedule that groups or logs by local date reads an anchor converted to another zone
        // as another day.
        $trigger = new PeriodicTrigger(
            new DateInterval('P1D'),
            new DateTimeImmutable('2026-05-01 00:00:00', new DateTimeZone('Europe/Skopje')),
        );

        $after = new DateTimeImmutable('2026-04-30 00:00:00', new DateTimeZone('UTC'));
        $before = new DateTimeImmutable('2026-05-04 00:00:00', new DateTimeZone('UTC'));

        $oneAtATime = [];
        $cursor = $after;

        while (null !== $next = $trigger->getNextRunDate($cursor)) {
            if ($next >= $before) {
                break;
            }

            $oneAtATime[] = $next->format('c');
            $cursor = $next;
        }

        $written = [];

        foreach ($trigger->getRunDates($after, $before, 10) as $date) {
            $written[] = $date->format('c');
        }

        $this->assertSame($oneAtATime, $written);
    }

    public function testAnIntervalThatDoesNotAdvanceIsRejectedByARangeAsWell(): void
    {
        // The anchor is a run date, so a range that starts before it would otherwise answer
        // with the anchor over and over, or walk backwards, without ever reaching the search
        // that catches this.
        $trigger = new PeriodicTrigger(new DateInterval('P0D'), new DateTimeImmutable('2026-05-01 00:00:00'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The interval does not advance the date');

        $trigger->getRunDates(
            new DateTimeImmutable('2026-04-01 00:00:00'),
            new DateTimeImmutable('2026-06-01 00:00:00'),
            3,
        );
    }

    public function testAnIntervalThatWalksBackwardsIsRejectedByARangeAsWell(): void
    {
        $trigger = new PeriodicTrigger(
            DateInterval::createFromDateString('-1 day'),
            new DateTimeImmutable('2026-05-01 00:00:00'),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The interval does not advance the date');

        $trigger->getRunDates(
            new DateTimeImmutable('2026-04-01 00:00:00'),
            new DateTimeImmutable('2026-06-01 00:00:00'),
            3,
        );
    }

    public function testABatchContinuedFromItsLastDateCoversTheRangeExactlyOnce(): void
    {
        $trigger = new PeriodicTrigger(600);
        $after = new DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $before = new DateTimeImmutable('2026-01-01 02:00:00+00:00');

        $batched = [];
        $cursor = $after;

        while ([] !== $batch = $trigger->getRunDates($cursor, $before, 4)) {
            $batched = [...$batched, ...$batch];
            $cursor = $batch[count($batch) - 1];
        }

        $this->assertEquals($trigger->getRunDates($after, $before, 100), $batched);
    }

    public function testTheAnchorItselfOpensTheRange(): void
    {
        $trigger = new PeriodicTrigger(3600, new DateTimeImmutable('2026-05-01 00:00:00+00:00'));

        $dates = $trigger->getRunDates(
            new DateTimeImmutable('2026-04-30 00:00:00+00:00'),
            new DateTimeImmutable('2026-05-01 02:30:00+00:00'),
            5,
        );

        $this->assertCount(3, $dates);
        $this->assertSame('2026-05-01 00:00:00', $dates[0]->format('Y-m-d H:i:s'));
    }

    public function testARangeStopsAtTheEndDateOfTheTrigger(): void
    {
        $trigger = new PeriodicTrigger(3600, null, new DateTimeImmutable('2026-01-01 03:00:00+00:00'));

        $dates = $trigger->getRunDates(
            new DateTimeImmutable('2026-01-01 00:30:00+00:00'),
            new DateTimeImmutable('2026-01-02 00:00:00+00:00'),
            10,
        );

        $this->assertCount(3, $dates);
        $this->assertSame('2026-01-01 03:00:00', $dates[2]->format('Y-m-d H:i:s'));
    }

    public function testBothBoundsAreExclusive(): void
    {
        $trigger = new PeriodicTrigger(3600, new DateTimeImmutable('2026-01-01 00:00:00+00:00'));

        $dates = $trigger->getRunDates(
            new DateTimeImmutable('2026-01-01 01:00:00+00:00'),
            new DateTimeImmutable('2026-01-01 03:00:00+00:00'),
            10,
        );

        $this->assertCount(1, $dates);
        $this->assertSame('2026-01-01 02:00:00', $dates[0]->format('Y-m-d H:i:s'));
    }

    public function testARangeThatEndsBeforeItStartsIsEmpty(): void
    {
        $trigger = new PeriodicTrigger(3600);

        $this->assertSame([], $trigger->getRunDates(
            new DateTimeImmutable('2026-01-02 00:00:00+00:00'),
            new DateTimeImmutable('2026-01-01 00:00:00+00:00'),
            5,
        ));
    }

    public function testAnExhaustedTriggerHasNoRange(): void
    {
        $trigger = new PeriodicTrigger(3600, null, new DateTimeImmutable('2026-01-01 00:00:00+00:00'));

        $this->assertSame([], $trigger->getRunDates(
            new DateTimeImmutable('2026-06-01 00:00:00+00:00'),
            new DateTimeImmutable('2026-06-02 00:00:00+00:00'),
            5,
        ));
    }

    public function testARangeHoldsNoMoreDatesThanAskedFor(): void
    {
        $trigger = new PeriodicTrigger(60);

        $dates = $trigger->getRunDates(
            new DateTimeImmutable('2026-01-01 00:00:00+00:00'),
            new DateTimeImmutable('2026-01-02 00:00:00+00:00'),
            3,
        );

        $this->assertCount(3, $dates);
        $this->assertSame('2026-01-01 00:01:00', $dates[0]->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-01 00:03:00', $dates[2]->format('Y-m-d H:i:s'));
    }

    public function testALimitOfNoneIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The limit must be greater than zero, "0" given.');

        (new PeriodicTrigger(3600))->getRunDates(
            new DateTimeImmutable('2026-01-01 00:00:00+00:00'),
            new DateTimeImmutable('2026-01-02 00:00:00+00:00'),
            0,
        );
    }

    public function testTheDateAskedAboutIsItselfAnAnswer(): void
    {
        $trigger = new PeriodicTrigger(3600);
        $onTheHour = new DateTimeImmutable('2026-01-01 05:00:00+00:00');

        $this->assertEquals($onTheHour, $trigger->getNextRunDateAtOrAfter($onTheHour));
        $this->assertSame('2026-01-01 06:00:00', $trigger->getNextRunDate($onTheHour)->format('Y-m-d H:i:s'));
    }

    public function testARunAlreadyAccountedForIsNotGivenAgain(): void
    {
        $trigger = new PeriodicTrigger(3600);
        $onTheHour = new DateTimeImmutable('2026-01-01 05:00:00+00:00');

        $this->assertSame(
            '2026-01-01 06:00:00',
            $trigger->getNextRunDateAtOrAfter($onTheHour, $onTheHour)?->format('Y-m-d H:i:s'),
        );
    }

    public function testAPhaseBeforeTheDateAskedAboutChangesNothing(): void
    {
        $trigger = new PeriodicTrigger(3600);
        $date = new DateTimeImmutable('2026-01-01 05:00:00+00:00');
        $phase = new DateTimeImmutable('2026-01-01 01:00:00+00:00');

        $this->assertEquals(
            $trigger->getNextRunDateAtOrAfter($date),
            $trigger->getNextRunDateAtOrAfter($date, $phase),
        );
    }

    public function testAPhaseCarriesARangePastTheRunsAlreadyAccountedFor(): void
    {
        $trigger = new PeriodicTrigger(3600, new DateTimeImmutable('2026-01-01 00:00:00+00:00'));
        $window = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        $dates = $trigger->getRunDates(
            $window,
            new DateTimeImmutable('2026-01-01 06:00:00+00:00'),
            10,
            new DateTimeImmutable('2026-01-01 03:00:00+00:00'),
        );

        $this->assertCount(2, $dates);
        $this->assertSame('2026-01-01 04:00:00', $dates[0]->format('Y-m-d H:i:s'));
    }

    public function testSeekingDoesNotMoveTheSequence(): void
    {
        // The anchor is what the dates are counted from, so asking from anywhere gives the same
        // dates: a process that restarts mid-backlog resumes the sequence it left.
        $trigger = new PeriodicTrigger(900, new DateTimeImmutable('2026-01-01 00:00:00+00:00'));
        $before = new DateTimeImmutable('2026-01-01 02:00:00+00:00');

        $whole = $trigger->getRunDates(new DateTimeImmutable('2026-01-01 00:00:00+00:00'), $before, 100);
        $tail = $trigger->getRunDates(new DateTimeImmutable('2026-01-01 01:07:00+00:00'), $before, 100);

        $this->assertEquals(array_slice($whole, 4), $tail);
    }

    /**
     * @param callable(): void $test
     */
    private function inTimezone(string $timezone, callable $test): void
    {
        $default = date_default_timezone_get();
        date_default_timezone_set($timezone);

        try {
            $test();
        } finally {
            date_default_timezone_set($default);
        }
    }
}
