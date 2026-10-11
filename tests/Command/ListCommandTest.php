<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\Scheduler\Command\ListCommand;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\Tests\Support\TestClock;

final class ListCommandTest extends TestCase
{
    public function testListsTasksWithNextRunDates(): void
    {
        $clock = new TestClock();

        $schedule = (new Schedule())->task(
            RecurringTask::cron('0 5 * * *', 'report:daily'),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);

        $tester = new CommandTester(new ListCommand($scheduler, $clock));
        $tester->execute([]);

        $display = $tester->getDisplay();

        $this->assertStringContainsString('cron(0 5 * * *)', $display);
        $this->assertStringContainsString('2026-01-01 05:00:00', $display);
        $this->assertSame(0, $tester->getStatusCode());
    }
}
