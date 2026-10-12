<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use Yiisoft\Scheduler\Callable\ContainerProvider;

interface CommandExecutorRuntimeFactory
{
    /** The provider belongs to the configuration process; it is never transported. */
    public function create(ContainerProvider $containerProvider): CommandExecutorRuntime;
}
