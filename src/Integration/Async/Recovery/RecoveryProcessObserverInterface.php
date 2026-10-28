<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Recovery;

use Yiisoft\Scheduler\Integration\Async\{ExecutionLaunchDescriptor, ExecutionProcessIdentity};
use Yiisoft\Scheduler\Integration\Async\Process\ProcessLookupResult;

/** Process lookup boundary; implementations must distinguish absence from failure. */
interface RecoveryProcessObserverInterface
{
    public function lookup(ExecutionLaunchDescriptor $descriptor, ?ExecutionProcessIdentity $expected = null): ProcessLookupResult;
}
