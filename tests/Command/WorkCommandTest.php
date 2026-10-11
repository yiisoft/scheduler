<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Yiisoft\Scheduler\Command\WorkCommand;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\Tests\Support\TestClock;

final class WorkCommandTest extends TestCase
{
    #[DataProvider('invalidSleepProvider')]
    public function testInvalidSleepIsRejected(string $sleep): void
    {
        $tester = new CommandTester(new WorkCommand($this->selfStoppingScheduler()));
        $exitCode = $tester->execute(['--sleep' => $sleep]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('--sleep', $tester->getDisplay());
    }

    public static function invalidSleepProvider(): array
    {
        return [
            'not a number' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-1'],
            'empty' => [''],
        ];
    }

    #[DataProvider('invalidMaxProcessesProvider')]
    public function testInvalidMaxProcessesIsRejected(string $maxProcesses): void
    {
        $tester = new CommandTester(new WorkCommand($this->selfStoppingScheduler()));
        $exitCode = $tester->execute(['--max-processes' => $maxProcesses]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('--max-processes', $tester->getDisplay());
    }

    public static function invalidMaxProcessesProvider(): array
    {
        return [
            'not a number' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-1'],
            'fractional' => ['1.5'],
            'empty' => [''],
        ];
    }

    public function testValidMaxProcessesIsAccepted(): void
    {
        $tester = new CommandTester(new WorkCommand($this->selfStoppingScheduler()));
        $exitCode = $tester->execute([
            '--sleep' => '0.01',
            '--sequential' => true,
            '--max-processes' => '2',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Scheduler stopped.', $tester->getDisplay());
    }

    public function testValidSleepRunsUntilStopped(): void
    {
        // Sequential, because the task stops the loop through a shared reference; a forked
        // child would stop only its own copy of the scheduler.
        $tester = new CommandTester(new WorkCommand($this->selfStoppingScheduler()));
        $exitCode = $tester->execute(['--sleep' => '0.01', '--sequential' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Scheduler stopped.', $tester->getDisplay());
    }

    /**
     * A scheduler with one due task that stops the loop as soon as it runs.
     */
    private function selfStoppingScheduler(): Scheduler
    {
        $clock = new TestClock();
        $scheduler = null;

        $schedule = (new Schedule())->task(
            RecurringTask::cron('* * * * *', static function () use (&$scheduler): void {
                $scheduler?->stop();
            }),
        );

        $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);
        $scheduler->tick();
        $clock->advance('+1 minute');

        return $scheduler;
    }
}
