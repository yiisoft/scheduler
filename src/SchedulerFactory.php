<?php

declare(strict_types=1);

namespace Yiisoft\Schedule;

use Yiisoft\Schedule\Binary\{WorkerAdapter,WorkerCompositionInput};
use Yiisoft\Schedule\Clock\SystemClock;
use Yiisoft\Schedule\Config\PhpConfigurationSource;
use Yiisoft\Schedule\Executor\ProcessLocalCommandExecutorRuntimeFactory;
use InvalidArgumentException;
use Yiisoft\Schedule\Integration\Async\{ExecutionWorkerEntrypoint,PreparedConfigurationExecutionWorkerRuntimeFactory,PreparedShellCommandRuntimeFactory,SystemMonotonicClock,UsleepPollWaiter};
use Yiisoft\Schedule\Integration\Async\Process\{SameFileExecutionWorkerProcessCommandBuilder,SymfonyExecutionWorkerLauncher,SymfonyProcessLauncher};
use Yiisoft\Schedule\Integration\Async\Store\{FileExecutionLedger,FileExecutionLedgerLocator,FileExecutionOccurrenceCheckpoint};
use Yiisoft\Schedule\Planner\{FilePlannerOwner,ParentLifecycleSupervisor,PlannerCompositionInput,SourcePlannerRuntime};

/** Process-local composition root for the planner and fresh-worker graphs. */
final class SchedulerFactory
{
    public function createPlanner(PlannerCompositionInput $input): SourcePlannerRuntime
    {
        $source = $input->source ?? new PhpConfigurationSource($input->configurationPath);
        $sourceId = $source->sourceId();
        $owner = $input->owner ?? (new FilePlannerOwner($input->stateDirectory))->acquire($sourceId);
        $wallClock = $input->wallClock ?? new SystemClock();
        $monotonicClock = $input->monotonicClock ?? new SystemMonotonicClock();
        $waiter = $input->waiter ?? new UsleepPollWaiter();
        $supervisor = $input->supervisor ?? new ParentLifecycleSupervisor($monotonicClock, $waiter);
        $processLauncher = new SymfonyProcessLauncher($monotonicClock);
        $shellFactory = $input->shellRuntimeFactory ?? new PreparedShellCommandRuntimeFactory(
            $processLauncher,
            $monotonicClock,
            $waiter,
            $input->shellVariableOverrides,
            getenv() ?: [],
            $input->stateDirectory,
        );
        $runtimeFactory = $input->runtimeFactory ?? new ProcessLocalCommandExecutorRuntimeFactory($shellFactory);
        $configuration = $source->prepare($runtimeFactory);
        $ledger = $input->ledger ?? new FileExecutionLedger($owner->stateDirectory() . '/ledger');
        $checkpoint = $input->occurrenceCheckpoint ?? new FileExecutionOccurrenceCheckpoint($owner->stateDirectory() . '/checkpoints');
        $launcher = $input->workerLauncher ?? new SymfonyExecutionWorkerLauncher(
            new SameFileExecutionWorkerProcessCommandBuilder(
                $input->workerExecutable,
                $input->configurationPath,
                $sourceId,
                $owner->stateDirectory(),
                overrides: $input->shellVariableOverrides,
                clock: $monotonicClock,
            ),
            $supervisor,
            $processLauncher,
        );

        return new SourcePlannerRuntime(
            $configuration,
            $source,
            $owner,
            $wallClock,
            $monotonicClock,
            $waiter,
            $launcher,
            $supervisor,
            $owner->stateDirectory(),
            $ledger,
            $checkpoint,
        );
    }

    public function createFreshWorker(WorkerCompositionInput $input): WorkerAdapter
    {
        if (!is_dir($input->stateDirectory)) {
            throw new InvalidArgumentException('Worker state directory is invalid.');
        }
        $source = $input->source ?? new PhpConfigurationSource($input->configurationPath);
        $ledgerLocator = $input->ledgerLocator ?? new FileExecutionLedgerLocator($input->stateDirectory);
        $wallClock = $input->wallClock ?? new SystemClock();
        $monotonicClock = $input->monotonicClock ?? new SystemMonotonicClock();
        $waiter = $input->waiter ?? new UsleepPollWaiter();
        $processLauncher = $input->processLauncher ?? new SymfonyProcessLauncher($monotonicClock);
        $shellFactory = $input->shellRuntimeFactory ?? new PreparedShellCommandRuntimeFactory(
            $processLauncher,
            $monotonicClock,
            $waiter,
            $input->shellVariableOverrides,
            getenv() ?: [],
            $input->stateDirectory,
        );
        $runtimeFactory = new PreparedConfigurationExecutionWorkerRuntimeFactory(
            $source,
            $input->runtimeFactory ?? new ProcessLocalCommandExecutorRuntimeFactory($shellFactory),
            $shellFactory,
        );
        $entrypoint = new ExecutionWorkerEntrypoint($ledgerLocator, $runtimeFactory, $wallClock);

        return new WorkerAdapter($entrypoint, $ledgerLocator);
    }
}
