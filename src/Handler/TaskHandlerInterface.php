<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Handler;

use Yiisoft\Scheduler\TaskContext;
use Yiisoft\Scheduler\Executor;

/**
 * Executes a due task.
 */
interface TaskHandlerInterface
{
    /**
     * @param mixed $task The task of the recurring task: a command of one of the kinds in
     * {@see Executor}, a callable, a console command name, or a queue message object.
     */
    public function handle(mixed $task, TaskContext $context): mixed;
}
