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

use function date_default_timezone_get;
use function date_default_timezone_set;

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
