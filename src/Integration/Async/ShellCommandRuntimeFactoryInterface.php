<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use Yiisoft\Schedule\Config\TimeoutPolicy;
use Yiisoft\Schedule\Executor\ShellCommandSpecification;

interface ShellCommandRuntimeFactoryInterface
{
    public function create(ShellCommandSpecification $command, TimeoutPolicy $timeout): ShellCommandRuntime;
}
