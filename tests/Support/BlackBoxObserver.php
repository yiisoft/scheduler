<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerLaunchHandle;
use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerLaunchRequest;
use Yiisoft\Scheduler\Integration\Async\Process\ExecutionWorkerProcessHandleObserverInterface;
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessHandleInterface, ProcessLaunchResult};

final class BlackBoxObserver implements ExecutionWorkerProcessHandleObserverInterface
{
    public ?ProcessHandleInterface $handle = null;

    public function retain(ExecutionWorkerLaunchRequest $request, ProcessLaunchResult $launch): ExecutionWorkerLaunchHandle
    {
        $this->handle = $launch->handle;
        return new ExecutionWorkerLaunchHandle('fixture-process');
    }
}
