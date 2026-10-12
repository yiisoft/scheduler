<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Planner;

use Psr\Clock\ClockInterface;
use Yiisoft\Schedule\Config\RuntimeConfigurationSourceInterface;
use Yiisoft\Schedule\Executor\{CommandExecutorRuntimeFactory,ShellVariableOverrides};
use Yiisoft\Schedule\Integration\Async\{ExecutionLedgerInterface,ExecutionOccurrenceCheckpointInterface,ExecutionWorkerLauncherInterface,MonotonicClockInterface,PollWaiterInterface,ShellCommandRuntimeFactoryInterface};

/** Immutable, typed process-local inputs for the planner composition root. */
final class PlannerCompositionInput
{
    public function __construct(
        public readonly string $configurationPath,
        public readonly string $stateDirectory,
        public readonly string $workerExecutable,
        public readonly ?RuntimeConfigurationSourceInterface $source = null,
        public readonly ?PlannerOwnerInterface $owner = null,
        public readonly ?ExecutionLedgerInterface $ledger = null,
        public readonly ?ExecutionOccurrenceCheckpointInterface $occurrenceCheckpoint = null,
        public readonly ?ClockInterface $wallClock = null,
        public readonly ?MonotonicClockInterface $monotonicClock = null,
        public readonly ?PollWaiterInterface $waiter = null,
        public readonly ?ExecutionWorkerLauncherInterface $workerLauncher = null,
        public readonly ?ParentLifecycleSupervisor $supervisor = null,
        public readonly ?ShellCommandRuntimeFactoryInterface $shellRuntimeFactory = null,
        public readonly ?CommandExecutorRuntimeFactory $runtimeFactory = null,
        public readonly ShellVariableOverrides $shellVariableOverrides = new ShellVariableOverrides(),
    ) {}
}
