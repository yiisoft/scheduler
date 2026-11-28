<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Handler;

use Yiisoft\Scheduler\Executor\CommandInterface;
use Yiisoft\Scheduler\Executor\ExecutorMap;
use Yiisoft\Scheduler\TaskContext;

/**
 * Runs the tasks declared as commands, by the executor registered for the exact class of each.
 *
 * A task that is not a command is passed on to the handler behind this one, which is where the
 * older task values — a callable, the name of a console command, a queue message — are read.
 * A task that is a command is never read that way: its class either has an executor or the run
 * fails saying so, rather than falling through to a handler that would guess at it.
 */
final class CommandTaskHandler implements TaskHandlerInterface
{
    public function __construct(
        private readonly ExecutorMap $executors,
        private readonly TaskHandlerInterface $fallback = new CallableTaskHandler(),
    ) {}

    public function handle(mixed $task, TaskContext $context): mixed
    {
        if (!$task instanceof CommandInterface) {
            return $this->fallback->handle($task, $context);
        }

        return $this->executors->for($task)->execute($task, $context);
    }
}
