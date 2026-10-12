<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Support;

use Yiisoft\Schedule\Integration\Async\ExecutionWorkerLaunchHandle;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerLaunchRequest;
use Yiisoft\Schedule\Integration\Async\Process\ExecutionWorkerProcessHandleObserverInterface;
use Yiisoft\Schedule\Integration\Async\Process\ProcessHandleInterface;

final class BlackBoxObserver implements ExecutionWorkerProcessHandleObserverInterface
{
    public ?ProcessHandleInterface $handle = null;

    public function retain(ExecutionWorkerLaunchRequest $request, ProcessHandleInterface $handle): ExecutionWorkerLaunchHandle
    {
        $this->handle = $handle;
        return new ExecutionWorkerLaunchHandle('fixture-process');
    }
}
