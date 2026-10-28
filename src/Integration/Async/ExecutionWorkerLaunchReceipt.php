<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use Yiisoft\Scheduler\Integration\Async\Process\ProcessLaunchResult;

/** Process-local facts returned by a completed worker launch. */
final class ExecutionWorkerLaunchReceipt
{
    public function __construct(
        public readonly ExecutionWorkerLaunchHandle $handle,
        public readonly ProcessLaunchResult $processLaunchResult,
    ) {}
}
