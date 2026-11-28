<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use Yiisoft\Scheduler\Executor\CommandInterface;
use Yiisoft\Scheduler\Executor\ExecutorInterface;
use Yiisoft\Scheduler\TaskContext;

/**
 * An executor that does nothing but count what it was asked to run.
 */
final class CountingExecutor implements ExecutorInterface
{
    public int $ran = 0;

    public function execute(CommandInterface $command, TaskContext $context): mixed
    {
        ++$this->ran;

        return null;
    }
}
