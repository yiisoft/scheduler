<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use Yiisoft\Scheduler\Config\TimeoutPolicy;
use Yiisoft\Scheduler\Executor\{ShellCommandSpecification,ShellVariableOverrides,ShellVariableResolver};
use Yiisoft\Scheduler\Integration\Async\Process\ProcessLauncherInterface;

/** Creates a fresh process-local runtime for one shell command execution. */
final class PreparedShellCommandRuntimeFactory implements ShellCommandRuntimeFactoryInterface
{
    /**
     * @param array<string, string> $environment
     */
    public function __construct(
        private readonly ProcessLauncherInterface $launcher,
        private readonly MonotonicClockInterface $clock,
        private readonly PollWaiterInterface $waiter,
        private readonly ShellVariableOverrides $overrides = new ShellVariableOverrides(),
        private readonly array $environment = [],
        private readonly ?string $cwd = null,
        private readonly ShellVariableResolver $resolver = new ShellVariableResolver(),
    ) {}

    public function create(ShellCommandSpecification $command, TimeoutPolicy $timeout): ShellCommandRuntime
    {
        return new ShellCommandRuntime(
            $command,
            $this->launcher,
            $timeout,
            $this->overrides,
            $this->environment,
            $this->cwd,
            $this->clock,
            $this->waiter,
            $this->resolver,
        );
    }
}
