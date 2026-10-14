<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use Yiisoft\Scheduler\Callable\ContainerProvider;
use Yiisoft\Scheduler\Integration\Async\ShellCommandRuntimeFactoryInterface;
use Yiisoft\Scheduler\Integration\Async\ExecutionOutcome;

/** Builds the command executor graph owned by one PHP process. */
final class ProcessLocalCommandExecutorRuntimeFactory implements CommandExecutorRuntimeFactory
{
    public function __construct(private readonly ShellCommandRuntimeFactoryInterface $shellRuntimeFactory) {}

    public function create(ContainerProvider $containerProvider): CommandExecutorRuntime
    {
        $map = new CommandExecutorMap([
            ShellCommand::class => new ShellCommandExecutor($this->shellRuntimeFactory),
            PhpCommand::class => new ShellCommandExecutor($this->shellRuntimeFactory),
            CallableCommand::class => new CallableExecutor($containerProvider),
        ]);

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
