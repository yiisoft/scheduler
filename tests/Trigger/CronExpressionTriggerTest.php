<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Trigger;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Trigger\{CronExpressionTrigger, TriggerRangeInterface};
use DateTimeZone;

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

    public function testRangeUsesTimezoneAndReturnsNewestChronologicalDates(): void
    {
        $trigger = new CronExpressionTrigger('0 9 * * *', 'Europe/Skopje');
        $dates = $trigger->getRunDates(
            new DateTimeImmutable('2026-01-01 08:00:00+00:00'),
            new DateTimeImmutable('2026-01-03 10:00:00+00:00'),
            1,
        );

        self::assertInstanceOf(TriggerRangeInterface::class, $trigger);
        self::assertCount(1, $dates);
        self::assertSame('2026-01-03 09:00', $dates[0]->setTimezone(new DateTimeZone('Europe/Skopje'))->format('Y-m-d H:i'));
        self::assertSame('2026-01-01 09:00', $trigger->getNextRunDateAtOrAfter(new DateTimeImmutable('2026-01-01 09:00:00+01:00'))->setTimezone(new DateTimeZone('Europe/Skopje'))->format('Y-m-d H:i'));
    }

    public function testLowerBoundIsRespectedForNonAlignedFrom(): void
    {
        $from = new DateTimeImmutable('2026-01-01 10:00:30+00:00');
        $query = new DateTimeImmutable('2026-01-01 09:00:00+00:00');
        $trigger = new CronExpressionTrigger('* * * * *', from: $from);

        $atOrAfter = $trigger->getNextRunDateAtOrAfter($query);
        $strict = $trigger->getNextRunDate($query);

        self::assertSame('2026-01-01 10:01:00', $atOrAfter->format('Y-m-d H:i:s'));
        self::assertSame('2026-01-01 10:01:00', $strict->format('Y-m-d H:i:s'));
        self::assertGreaterThanOrEqual($from, $atOrAfter);
        self::assertGreaterThanOrEqual($from, $strict);
    }

    public function testRangeIncludesWholeSecondBeforeFractionalBoundOnly(): void
    {
        $trigger = new CronExpressionTrigger('* * * * *');
        $after = new DateTimeImmutable('2026-01-01 00:09:00+00:00');

        self::assertSame(
            ['2026-01-01 00:10:00'],
            array_map(
                static fn(DateTimeImmutable $date): string => $date->format('Y-m-d H:i:s'),
                $trigger->getRunDates($after, new DateTimeImmutable('2026-01-01 00:10:00.500000+00:00'), 10),
            ),
        );
        self::assertSame([], $trigger->getRunDates($after, new DateTimeImmutable('2026-01-01 00:10:00+00:00'), 10));
    }

    public function testRangeHasDeterministicSpringDstBoundary(): void
    {
        $trigger = new CronExpressionTrigger('30 2 * * *', 'Europe/Berlin');
        $next = $trigger->getNextRunDateAtOrAfter(new DateTimeImmutable('2026-03-29 00:00:00+00:00'));

        self::assertSame('2026-03-29 03:30', $next->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d H:i'));
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

    public function testToString(): void
    {
        $this->assertSame('cron(0 5 * * *)', (string) new CronExpressionTrigger('0 5 * * *'));
    }
}
