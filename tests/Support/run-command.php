<?php

declare(strict_types=1);

/**
 * Runs `schedule:run` or `schedule:work` with the real console output, so a test can see
 * what each writes to a terminal. Called through a pseudo-terminal by
 * ProgramStatusOnATerminalTest.
 *
 * @var list<string> $argv The autoloader to use, the command to run, then its options.
 */

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Yiisoft\Scheduler\Command\RunCommand;
use Yiisoft\Scheduler\Command\WorkCommand;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\Tests\Support\InMemoryCache;
use Yiisoft\Scheduler\Tests\Support\TestClock;

require $argv[1];

$clock = new TestClock();
$options = [];

if ('work' === $argv[2]) {
    $scheduler = null;

    // One due task, which stops the loop as it runs: a daemon that returns by itself.
    $schedule = (new Schedule())->task(
        RecurringTask::cron('* * * * *', static function () use (&$scheduler): void {
            $scheduler?->stop();
        }),
    );

    $scheduler = new Scheduler([$schedule], new CallableTaskHandler(), $clock);
    $scheduler->tick();
    $clock->advance('+1 minute');

    $command = new WorkCommand($scheduler);
    $command->setName('schedule:work');
    $options = ['--sleep' => '0.01', '--sequential' => true];
} else {
    $scheduler = new Scheduler(
        [(new Schedule())->stateful(new InMemoryCache())],
        new CallableTaskHandler(),
        $clock,
    );

    $command = new RunCommand($scheduler);
    $command->setName('schedule:run');
}

foreach (\array_slice($argv, 3) as $option) {
    $options[$option] = true;
}

exit($command->run(new ArrayInput($options), new ConsoleOutput()));
