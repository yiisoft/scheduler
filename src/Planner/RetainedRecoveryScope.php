<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use Yiisoft\Scheduler\Integration\Async\{ExecutionStartObservation, ExecutionWorkerLaunchHandle, WorkerLifecyclePolicy};
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessHandleInterface, ProcessIdentity};
use Yiisoft\Scheduler\Integration\Async\Recovery\RecoveryObservation;

/** Parent-owned process scope recovered from persisted execution facts. */
final class RetainedRecoveryScope
{
    public bool $softSignalled = false;
    public bool $hardSignalled = false;

    public function __construct(
        public readonly ExecutionWorkerLaunchHandle $handle,
        public readonly RecoveryObservation $observation,
        public readonly ProcessHandleInterface $process,
        public readonly ProcessIdentity $identity,
        public readonly ?WorkerLifecyclePolicy $lifecyclePolicy,
        public readonly ?float $startedAt,
        public readonly ?int $wallStartedAtMicros,
        public readonly ?ExecutionStartObservation $attemptStart,
    ) {}
}
