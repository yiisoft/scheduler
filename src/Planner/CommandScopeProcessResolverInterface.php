<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use Yiisoft\Scheduler\Integration\Async\Process\ProcessHandleInterface;

interface CommandScopeProcessResolverInterface
{
    public function resolve(RetainedWorker $worker): ?ProcessHandleInterface;
}
