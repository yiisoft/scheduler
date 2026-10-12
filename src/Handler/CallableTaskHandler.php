<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Handler;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Exception\RuntimeException;
use Yiisoft\Scheduler\TaskContext;

use function is_callable;
use function is_string;
use function sprintf;

/**
 * The default task handler: invokes callables and, when the console application is
 * available, runs tasks named as console commands. A command exiting with a non-zero code is
 * a failed task.
 */
final class CallableTaskHandler implements TaskHandlerInterface
{
    public function __construct(
        private readonly ?Application $application = null,
    ) {}

    public function handle(mixed $task, TaskContext $context): mixed
    {
        if (is_string($task)) {
            return $this->runCommand($task);
        }

        if (is_callable($task)) {
            return $task($context);
        }

        throw new LogicException(
            sprintf(
                'Unable to run a task of type "%s". Configure a queue producer to push object tasks, or use a callable or a console command name.',
                get_debug_type($task),
            ),
        );
    }

    private function runCommand(string $command): int
    {
        if (null === $this->application) {
            throw new LogicException(
                sprintf('Unable to run the "%s" console command: the task handler has no console application.', $command),
            );
        }

        $input = new StringInput($command);
        $input->setInteractive(false);

        $exitCode = $this->application->doRun($input, new NullOutput());

        // A command reports a failure through its exit code rather than by throwing, and the
        // code alone would pass for the result of a successful run. Only the name goes into
        // the message: it ends up in logs, where the arguments and options may not belong.
        if (0 !== $exitCode) {
            throw new RuntimeException(
                sprintf('The "%s" console command exited with code %d.', $input->getFirstArgument() ?? $command, $exitCode),
            );
        }

        return $exitCode;
    }
}
