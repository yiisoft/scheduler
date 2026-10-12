<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use Yiisoft\Scheduler\Integration\Async\{ExecutionWorkerLaunchHandle, ExecutionWorkerLaunchRequest};

/** Application-owned retention and observation boundary for a started process. */
interface ExecutionWorkerProcessHandleObserverInterface
{
    public function retain(ExecutionWorkerLaunchRequest $request, ProcessHandleInterface $handle): ExecutionWorkerLaunchHandle;
}
