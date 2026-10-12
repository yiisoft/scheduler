<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Planner;

use Yiisoft\Schedule\Integration\Async\WorkerLifecyclePolicy;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerLaunchHandle;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerLaunchRequest;
use Yiisoft\Schedule\Integration\Async\Process\ProcessHandleInterface;

/** A launched worker remains owned until its complete process scope is quiescent. */
final class RetainedWorker
{
    public bool $softSignalled = false;
    public bool $hardSignalled = false;

    public function __construct(
        public readonly ExecutionWorkerLaunchHandle $launch,
        public readonly ExecutionWorkerLaunchRequest $request,
        public readonly ProcessHandleInterface $process,
        public readonly float $launchedAt,
        public readonly ?WorkerLifecyclePolicy $lifecyclePolicy,
    ) {}
}
