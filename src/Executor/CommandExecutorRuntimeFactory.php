<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Executor;

use Yiisoft\Schedule\Callable\ContainerProvider;

interface CommandExecutorRuntimeFactory
{
    /** The provider belongs to the configuration process; it is never transported. */
    public function create(ContainerProvider $containerProvider): CommandExecutorRuntime;
}
