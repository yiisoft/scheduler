<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async\Process;

interface ProcessLauncherInterface
{
    public function launch(ProcessLaunchRequest $request): ProcessLaunchResult;
}
