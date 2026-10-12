<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Executor;

use Yiisoft\Schedule\Integration\Async\ExecutionOutcome;

interface ExecutorInterface
{
    public function execute(ExecutionContext $context): ExecutionOutcome;
}
