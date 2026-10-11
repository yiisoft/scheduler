<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Trigger;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Trigger\PeriodicTrigger;

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
}
