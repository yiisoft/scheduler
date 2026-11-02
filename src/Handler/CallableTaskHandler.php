<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Handler;

use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\TaskContext;

use function get_debug_type;
use function is_callable;
use function is_string;
use function sprintf;

/**
 * The default task handler: invokes a callable task, and refuses anything else.
 *
 * It is the end of the chain and depends on nothing outside this package. A task named as a
 * console command is run by {@see ConsoleTaskHandler} in front of it, a message task is pushed
 * by {@see QueueTaskHandler}, and each of those passes on what it does not recognise.
 */
final class CallableTaskHandler implements TaskHandlerInterface
{
    public function handle(mixed $task, TaskContext $context): mixed
    {
        // Before the callable check, as it was before this handler was split: a string naming a
        // function is callable, and a string task has always meant the name of a command.
        if (is_string($task)) {
            throw new LogicException(
                sprintf(
                    'Unable to run the "%s" task: a string task is the name of a console command, which needs "%s" in front of this handler.',
                    $task,
                    ConsoleTaskHandler::class,
                ),
            );
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
}
