<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Trigger;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Trigger\CronExpressionTrigger;
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
