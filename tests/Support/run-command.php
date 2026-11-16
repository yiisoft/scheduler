<?php

declare(strict_types=1);

/**
 * Runs `schedule:run` with the real console output, so a test can see what it writes to a
 * terminal. Called through a pseudo-terminal by ProgramStatusOnATerminalTest.
 *
 * @var list<string> $argv The autoloader to use, then the options to pass to the command.
 */

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Yiisoft\Scheduler\Command\RunCommand;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\Tests\Support\InMemoryCache;
use Yiisoft\Scheduler\Tests\Support\TestClock;

require $argv[1];

$scheduler = new Scheduler(
    [(new Schedule())->stateful(new InMemoryCache())],
    new CallableTaskHandler(),
    new TestClock(),
);

$command = new RunCommand($scheduler);
$command->setName('schedule:run');

$options = [];

foreach (\array_slice($argv, 2) as $option) {
    $options[$option] = true;
}

exit($command->run(new ArrayInput($options), new ConsoleOutput()));
