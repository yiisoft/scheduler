<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

/** Marker for application runtime produced after a successful worker claim. */
interface ExecutionWorkerRuntimeInterface
{
    public function execute(): ExecutionOutcome;
}
