<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Executor;

use Yiisoft\Schedule\Integration\Async\ExecutionOutcome;

/** A process-local runtime created for one command execution. */
interface CommandExecutorRuntime
{
    /** Builds the process-local exact-class executor map. */
    public function commandExecutorMap(): CommandExecutorMap;

    public function execute(ExecutionContext $context): ExecutionOutcome;
}
