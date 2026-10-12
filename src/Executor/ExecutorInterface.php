<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use Yiisoft\Scheduler\Integration\Async\ExecutionOutcome;

interface ExecutorInterface
{
    public function execute(ExecutionContext $context): ExecutionOutcome;
}
