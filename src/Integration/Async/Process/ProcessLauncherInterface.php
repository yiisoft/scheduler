<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

interface ProcessLauncherInterface
{
    public function launch(ProcessLaunchRequest $request): ProcessLaunchResult;
}
