<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use Yiisoft\Scheduler\Integration\Async\{ExecutionLaunchDescriptor, ExecutionWorkerLaunchRequest};

/** Durable handoff boundary; implementations must commit before start(). */
interface LaunchDescriptorStoreInterface
{
    public function record(ExecutionWorkerLaunchRequest $request, ExecutionLaunchDescriptor $descriptor): void;
}
