<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use InvalidArgumentException;
use Throwable;
use Yiisoft\Scheduler\Config\TimeoutPolicy;
use Yiisoft\Scheduler\Integration\Async\{ExecutionOutcome,ShellCommandRuntimeFactoryInterface};

final class ShellCommandExecutor implements ExecutorInterface
{
    public function __construct(private readonly ShellCommandRuntimeFactoryInterface $runtimeFactory) {}

    public function execute(ExecutionContext $context): ExecutionOutcome
    {
        if (!$context->command instanceof ShellCommand) {
            throw new InvalidArgumentException('Shell executor requires a shell command.');
        }

        try {
            return $this->runtimeFactory
                ->create($context->command->specification(), $context->timeout ?? new TimeoutPolicy())
                ->execute();
        } catch (Throwable) {
            return new ExecutionOutcome(ExecutionOutcome::FAILED, 'shell.execution_failed');
        }
    }
}
