<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Config;

use LogicException;
use Yiisoft\Scheduler\Callable\ContainerProvider;
use Yiisoft\Scheduler\Config\TimeoutPolicy;
use Yiisoft\Scheduler\Executor\{CommandExecutorRuntime,CommandExecutorRuntimeFactory,ProcessLocalCommandExecutorRuntimeFactory,ShellCommandSpecification};
use Yiisoft\Scheduler\Integration\Async\{ShellCommandRuntime,ShellCommandRuntimeFactoryInterface};

/** Runtime graph used by configuration tests; execution is intentionally out of scope. */
final class RuntimeFactoryFixture implements CommandExecutorRuntimeFactory
{
    private readonly ProcessLocalCommandExecutorRuntimeFactory $factory;

    public function __construct()
    {
        $this->factory = new ProcessLocalCommandExecutorRuntimeFactory(new class implements ShellCommandRuntimeFactoryInterface {
            public function create(ShellCommandSpecification $command, TimeoutPolicy $timeout): ShellCommandRuntime
            {
                throw new LogicException('Shell execution is not part of configuration tests.');
            }
        });
    }

    public function create(ContainerProvider $containerProvider): CommandExecutorRuntime
    {
        return $this->factory->create($containerProvider);
    }
}
