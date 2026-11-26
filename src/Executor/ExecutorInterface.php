<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use Yiisoft\Scheduler\TaskContext;

/**
 * Runs the commands of one command class.
 *
 * An executor is chosen by the exact class of the command it is given, so it may assume the
 * class it was registered for and nothing wider. What it returns is the result of the run, kept
 * as the scheduler's events report it; a failure is thrown rather than returned.
 */
interface ExecutorInterface
{
    public function execute(CommandInterface $command, TaskContext $context): mixed;
}
