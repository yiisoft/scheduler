<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Exception\RuntimeException;
use Yiisoft\Scheduler\TaskContext;

use function array_replace;
use function getenv;
use function is_resource;
use function proc_close;
use function proc_open;
use function sprintf;

use const DIRECTORY_SEPARATOR;

/**
 * Runs a {@see ProcessCommand} in a process of its own and waits for it to finish.
 *
 * The program and its arguments are handed to the operating system as a list, so no shell reads
 * them and nothing in an argument is expanded, quoted away or split.
 *
 * What the program writes goes to the null device rather than to the scheduler's own output,
 * which would otherwise mix the two, and rather than to a pipe this would have to keep draining
 * while the program runs. A program exiting with a code other than zero is a failed task,
 * reported the way a console command that exits non-zero is.
 *
 * There is no time limit here. How long a task may run, and what stops it, is the business of
 * the scheduler that starts it rather than of this executor.
 */
final class ProcessExecutor implements ExecutorInterface
{
    public function execute(CommandInterface $command, TaskContext $context): mixed
    {
        if (!$command instanceof ProcessCommand) {
            throw new LogicException(
                sprintf('This executor runs "%s" commands, and was given "%s".', ProcessCommand::class, $command::class),
            );
        }

        return $this->run($command->getCommandLine(), $command->getEnvironment(), $command->getWorkingDirectory());
    }

    /**
     * @param non-empty-list<string> $commandLine
     * @param array<string, string> $environment
     */
    private function run(array $commandLine, array $environment, ?string $workingDirectory): int
    {
        $null = '\\' === DIRECTORY_SEPARATOR ? 'nul' : '/dev/null';

        // Input included: a program that reads from the terminal nobody is at would otherwise
        // wait for an answer that never comes.
        $descriptors = [
            0 => ['file', $null, 'r'],
            1 => ['file', $null, 'w'],
            2 => ['file', $null, 'w'],
        ];

        // An empty array would be the whole environment of the child rather than nothing added
        // to it, so what this process has is passed along explicitly when there is something to
        // add to it, and left alone when there is not.
        $inherited = [] === $environment ? null : array_replace(getenv(), $environment);

        $pipes = [];
        $process = @proc_open($commandLine, $descriptors, $pipes, $workingDirectory, $inherited);

        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('Unable to start "%s".', $commandLine[0]));
        }

        $exitCode = proc_close($process);

        // The code alone would pass for the result of a successful run, and only the program
        // goes into the message: it ends up in logs, where the arguments may not belong.
        if (0 !== $exitCode) {
            throw new RuntimeException(sprintf('The "%s" program exited with code %d.', $commandLine[0], $exitCode));
        }

        return $exitCode;
    }
}
