<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\Scheduler\Command\RunCommand;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\Tests\Support\InMemoryCache;
use Yiisoft\Scheduler\Tests\Support\TestClock;

final class RunCommandTest extends TestCase
{
    public function testNoTaskIsDue(): void
    {
        $schedule = (new Schedule())->stateful(new InMemoryCache());

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), new TestClock());

        $tester = new CommandTester(new RunCommand($scheduler));
        $tester->execute([]);

        $this->assertStringContainsString('No task is due.', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testDueTaskRuns(): void
    {
        $clock = new TestClock();
        $ran = 0;

        $schedule = (new Schedule())
            ->stateful(new InMemoryCache())
            ->task(
                RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                    ++$ran;
                }),
            );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);
        $scheduler->tick();
        $clock->advance('+1 minute');

        $tester = new CommandTester(new RunCommand($scheduler));
        $tester->execute([]);

        $this->assertSame(1, $ran);
        $this->assertStringContainsString('Ran 1 task(s).', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testScheduleWithoutAPersistedCheckpointIsReported(): void
    {
        $schedule = (new Schedule('reports'))->task(
            RecurringTask::cron('* * * * *', 'report:daily'),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), new TestClock());

        $tester = new CommandTester(new RunCommand($scheduler));
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('reports', $tester->getDisplay());
        $this->assertStringContainsString('stateful', $tester->getDisplay());
    }
}
