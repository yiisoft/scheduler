<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

/** The application chooses how and where to launch; this port receives no process policy. */
interface ExecutionWorkerLauncherInterface
{
    public function launch(ExecutionWorkerLaunchRequest $request): ExecutionWorkerLaunchHandle;
}
