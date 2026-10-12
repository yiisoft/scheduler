<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Support;

use LogicException;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerLaunchHandle;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerLaunchRequest;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerLauncherInterface;

final class BlackBoxApplicationLauncher implements ExecutionWorkerLauncherInterface
{
    public function launch(ExecutionWorkerLaunchRequest $request): ExecutionWorkerLaunchHandle
    {
        throw new LogicException('The observation fixture does not launch a worker.');
    }
}
