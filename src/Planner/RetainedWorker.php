<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use Yiisoft\Scheduler\Integration\Async\ExecutionStartObservation;
use Yiisoft\Scheduler\Integration\Async\WorkerLifecyclePolicy;
use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerLaunchHandle;
use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerLaunchRequest;
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessHandleInterface, ProcessIdentity};

/** A launched worker remains owned until its complete process scope is quiescent. */
final class RetainedWorker
{
    public bool $softSignalled = false;
    public bool $hardSignalled = false;
    public bool $commandSoftSignalled = false;
    public bool $commandHardSignalled = false;

    public function __construct(
        public readonly ExecutionWorkerLaunchHandle $launch,
        public readonly ExecutionWorkerLaunchRequest $request,
        public readonly ProcessHandleInterface $process,
        /** Actual monotonic process-start time, or null for incomplete reattachments. */
        public readonly ?float $launchedAt,
        public readonly ?int $wallLaunchedAtMicros,
        /** Exact supplied attempt boundary for a complete reattachment. */
        public readonly ?ExecutionStartObservation $attemptStartObservation,
        public readonly ?ProcessIdentity $processIdentity,
        public readonly ?WorkerLifecyclePolicy $lifecyclePolicy,
    ) {}
}
