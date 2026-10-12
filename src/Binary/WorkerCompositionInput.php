<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Binary;

use Psr\Clock\ClockInterface;
use Yiisoft\Scheduler\Config\RuntimeConfigurationSourceInterface;
use Yiisoft\Scheduler\Executor\{CommandExecutorRuntimeFactory,ShellVariableOverrides};
use Yiisoft\Scheduler\Integration\Async\{ExecutionLedgerLocatorInterface,MonotonicClockInterface,PollWaiterInterface,ShellCommandRuntimeFactoryInterface};
use Yiisoft\Scheduler\Integration\Async\Process\ProcessLauncherInterface;

/** Immutable, typed process-local inputs for fresh-worker composition. */
final class WorkerCompositionInput
{
    public function __construct(
        public readonly string $configurationPath,
        public readonly string $stateDirectory,
        public readonly ShellVariableOverrides $shellVariableOverrides = new ShellVariableOverrides(),
        public readonly ?RuntimeConfigurationSourceInterface $source = null,
        public readonly ?ExecutionLedgerLocatorInterface $ledgerLocator = null,
        public readonly ?ClockInterface $wallClock = null,
        public readonly ?MonotonicClockInterface $monotonicClock = null,
        public readonly ?PollWaiterInterface $waiter = null,
        public readonly ?ProcessLauncherInterface $processLauncher = null,
        public readonly ?ShellCommandRuntimeFactoryInterface $shellRuntimeFactory = null,
        public readonly ?CommandExecutorRuntimeFactory $runtimeFactory = null,
    ) {}
}
