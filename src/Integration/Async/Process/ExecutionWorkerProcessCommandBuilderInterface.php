<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerLaunchRequest;

/** Application-owned translation of an opaque worker request to a process request. */
interface ExecutionWorkerProcessCommandBuilderInterface
{
    public function build(ExecutionWorkerLaunchRequest $request): ProcessLaunchRequest;
}
