<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use Yiisoft\Scheduler\Config\TimeoutPolicy;
use Yiisoft\Scheduler\Executor\ShellCommandSpecification;

interface ShellCommandRuntimeFactoryInterface
{
    public function create(ShellCommandSpecification $command, TimeoutPolicy $timeout): ShellCommandRuntime;
}
