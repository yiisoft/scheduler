<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use Yiisoft\Scheduler\Callable\ContainerProvider;
use Yiisoft\Scheduler\Executor\{CallableCommand, CallableExecutor, CommandExecutorMap, CommandExecutorRuntime, CommandExecutorRuntimeFactory, ExecutionContext};
use Yiisoft\Scheduler\Integration\Async\ExecutionOutcome;

final class TestCommandExecutorRuntimeFactory implements CommandExecutorRuntimeFactory
{
    public function __construct(private readonly ?CommandExecutorMap $customMap = null) {}

    public function create(ContainerProvider $containerProvider): CommandExecutorRuntime
    {
        $map = $this->customMap ?? new CommandExecutorMap([CallableCommand::class => new CallableExecutor($containerProvider)]);

        return new class ($map) implements CommandExecutorRuntime {
            public function __construct(private readonly CommandExecutorMap $map) {}

            public function commandExecutorMap(): CommandExecutorMap
            {
                return $this->map;
            }

            public function execute(ExecutionContext $context): ExecutionOutcome
            {
                return $this->map->executorFor($context->command)->execute($context);
            }
        };
    }
}
