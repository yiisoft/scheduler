<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Trigger;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Trigger\{PeriodicTrigger, TriggerRangeInterface};

final class PeriodicTriggerTest extends TestCase
{
    public function testSecondsInterval(): void
    {
        $trigger = new PeriodicTrigger(300);
        $lastRun = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        $this->assertSame('2026-01-01 00:05:00', $trigger->getNextRunDate($lastRun)->format('Y-m-d H:i:s'));
    }

    public function testRangeSeeksNewestBoundedDatesWithoutChangingAnchor(): void
    {
        $trigger = new PeriodicTrigger(300, new DateTimeImmutable('2026-01-01 00:00:00+00:00'));
        $after = new DateTimeImmutable('2026-01-01 00:07:00+00:00');
        $before = new DateTimeImmutable('2026-01-01 00:23:00+00:00');

        self::assertInstanceOf(TriggerRangeInterface::class, $trigger);
        self::assertSame('2026-01-01 00:10:00', $trigger->getNextRunDateAtOrAfter($after)->format('Y-m-d H:i:s'));
        self::assertSame(
            ['00:15', '00:20'],
            array_map(static fn(DateTimeImmutable $date): string => $date->format('H:i'), $trigger->getRunDates($after, $before, 2)),
        );
    }

    public function testAnchoredRangeUsesAnchorPhase(): void
    {
        $trigger = new PeriodicTrigger(300, new DateTimeImmutable('2026-01-01 10:00:00Z'));

        self::assertSame(
            ['10:00', '10:05'],
            array_map(
                static fn(DateTimeImmutable $date): string => $date->format('H:i'),
                $trigger->getRunDates(
                    new DateTimeImmutable('2026-01-01 09:59:00Z'),
                    new DateTimeImmutable('2026-01-01 10:10:00Z'),
                    10,
                ),
            ),
        );
    }

    public function testAnchoredRangePreservesFractionalAnchor(): void
    {
        $trigger = new PeriodicTrigger(5, new DateTimeImmutable('2026-01-01 00:00:00.500000Z'));

        self::assertSame(
            ['00:00:00.500000', '00:00:05.500000'],
            array_map(
                static fn(DateTimeImmutable $date): string => $date->format('H:i:s.u'),
                $trigger->getRunDates(
                    new DateTimeImmutable('2026-01-01 00:00:00Z'),
                    new DateTimeImmutable('2026-01-01 00:00:06Z'),
                    10,
                ),
            ),
        );
        self::assertSame(
            '00:00:05.500000',
            $trigger->getNextRunDateAtOrAfter(new DateTimeImmutable('2026-01-01 00:00:01Z'))->format('H:i:s.u'),
        );
    }

    public function testUnanchoredRangeUsesSuppliedPhaseAndIncludesExactNow(): void
    {
        $trigger = new PeriodicTrigger(300);
        $phase = new DateTimeImmutable('2026-01-01 00:00:00+00:00');
        $now = new DateTimeImmutable('2026-01-01 00:10:00+00:00');

        self::assertSame('00:10', $trigger->getNextRunDateAtOrAfter($now, $phase)->format('H:i'));
        self::assertSame(['00:05'], array_map(
            static fn(DateTimeImmutable $date): string => $date->format('H:i'),
            $trigger->getRunDates(new DateTimeImmutable('2026-01-01 00:00:00+00:00'), $now, 10, $phase),
        ));
    }

    public function testCalendarIntervalRangeRemainsAnchored(): void
    {
        $trigger = new PeriodicTrigger(new DateInterval('P1M'), new DateTimeImmutable('2026-01-01 00:00:00+00:00'));
        $dates = $trigger->getRunDates(new DateTimeImmutable('2026-01-15 00:00:00+00:00'), new DateTimeImmutable('2026-04-15 00:00:00+00:00'), 10);

        self::assertSame(['2026-02-01', '2026-03-01', '2026-04-01'], array_map(static fn(DateTimeImmutable $date): string => $date->format('Y-m-d'), $dates));
    }

    public function testRangeStopsAtUntil(): void
    {
        $trigger = new PeriodicTrigger(300, new DateTimeImmutable('2026-01-01 00:00:00+00:00'), new DateTimeImmutable('2026-01-01 00:10:00+00:00'));

        self::assertSame([], $trigger->getRunDates(new DateTimeImmutable('2026-01-01 00:10:00+00:00'), new DateTimeImmutable('2026-01-01 00:30:00+00:00'), 10));
        self::assertNull($trigger->getNextRunDateAtOrAfter(new DateTimeImmutable('2026-01-01 00:11:00+00:00')));
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
        $this->assertNull($trigger->getNextRunDate(new DateTimeImmutable('2026-01-01 00:56:00+00:00')));
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
}
