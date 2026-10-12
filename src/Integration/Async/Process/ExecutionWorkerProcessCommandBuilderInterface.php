<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async\Process;

use Yiisoft\Schedule\Integration\Async\ExecutionWorkerLaunchRequest;

/** Application-owned translation of an opaque worker request to a process request. */
interface ExecutionWorkerProcessCommandBuilderInterface
{
    public function build(ExecutionWorkerLaunchRequest $request): ProcessLaunchRequest;
}
