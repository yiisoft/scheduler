<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Handler;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Yiisoft\Scheduler\Exception\RuntimeException;
use Yiisoft\Scheduler\TaskContext;

use function is_string;
use function sprintf;

/**
 * A task handler running tasks named as console commands, while everything else goes on to the
 * handler behind it. A command exiting with a non-zero code is a failed task.
 *
 * Requires symfony/console to be installed. A schedule of callables, or of messages pushed to a
 * queue, needs neither this handler nor that package.
 */
final class ConsoleTaskHandler implements TaskHandlerInterface
{
    public function __construct(
        private readonly Application $application,
        private readonly TaskHandlerInterface $fallback = new CallableTaskHandler(),
    ) {}

    public function handle(mixed $task, TaskContext $context): mixed
    {
        if (!is_string($task)) {
            return $this->fallback->handle($task, $context);
        }

        $input = new StringInput($task);
        $input->setInteractive(false);

        $exitCode = $this->application->doRun($input, new NullOutput());

        // A command reports a failure through its exit code rather than by throwing, and the
        // code alone would pass for the result of a successful run. Only the name goes into
        // the message: it ends up in logs, where the arguments and options may not belong.
        if (0 !== $exitCode) {
            throw new RuntimeException(
                sprintf('The "%s" console command exited with code %d.', $input->getFirstArgument() ?? $task, $exitCode),
            );
        }

        return $exitCode;
    }
}
