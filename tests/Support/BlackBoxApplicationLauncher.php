<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use LogicException;
use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerLaunchReceipt;
use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerLaunchRequest;
use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerLauncherInterface;

final class BlackBoxApplicationLauncher implements ExecutionWorkerLauncherInterface
{
    public function launch(ExecutionWorkerLaunchRequest $request): ExecutionWorkerLaunchReceipt
    {
        throw new LogicException('The observation fixture does not launch a worker.');
    }
}
