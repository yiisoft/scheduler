<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Planner;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Yiisoft\Scheduler\Binary\{WorkerAdapter, WorkerCompositionInput};
use Yiisoft\Scheduler\Callable\ContainerProvider;
use Yiisoft\Scheduler\Config\{PreparedConfiguration, RuntimeConfigurationSourceInterface, TaskDefinition, TriggerDefinition};
use Yiisoft\Scheduler\Executor\{CommandExecutorMap, CommandExecutorRuntimeFactory, ProcessLocalCommandExecutorRuntimeFactory, ShellCommand};
use Yiisoft\Scheduler\Integration\Async\PreparedShellCommandRuntimeFactory;
use Yiisoft\Scheduler\Integration\Async\{ExecutionClaimResult, ExecutionFence, ExecutionIdentity, ExecutionLedgerInterface, ExecutionLedgerLocatorInterface, ExecutionLedgerRecord, ExecutionOccurrenceCheckpointInterface, ExecutionOccurrenceKey, ExecutionTerminalResult, ExecutionWorkerCapability, ExecutionWorkerInvocationResult, MonotonicClockInterface, PollWaiterInterface};
use Yiisoft\Scheduler\Planner\{PlannerCompositionInput, PlannerOwnerInterface, SourcePlannerRuntime};
use Yiisoft\Scheduler\SchedulerFactory;
use Yiisoft\Scheduler\ExecutionMode;
use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerLauncherInterface;
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessHandleInterface, ProcessLaunchResult, ProcessLauncherInterface, ProcessResult};
use Yiisoft\Scheduler\Task\TaskId;

use const DIRECTORY_SEPARATOR;

final class SchedulerFactoryCompositionTest extends TestCase
{
    public function testPlannerCompositionUsesTheSuppliedOccurrenceCheckpointDuringTick(): void
    {
        $stateDirectory = $this->temporaryDirectory('schedule-planner-');
        $checkpoint = $this->createMock(ExecutionOccurrenceCheckpointInterface::class);
        $checkpoint->expects(self::once())->method('read')->with('default', 'task')->willReturn(null);
        $checkpoint->expects(self::once())->method('initialize')->with('default', 'task', 't1700000000');

        try {
            $input = $this->plannerInput($checkpoint, $stateDirectory);
            $planner = (new SchedulerFactory())->createPlanner($input);

            self::assertInstanceOf(SourcePlannerRuntime::class, $planner);
            self::assertSame(0, $planner->tick()->admitted);
        } finally {
            $this->removeDirectory($stateDirectory);
        }
    }

    public function testPlannerAndFreshWorkerUseDistinctOwnedCompositionState(): void
    {
        $plannerState = $this->temporaryDirectory('schedule-planner-');
        $workerState = $this->temporaryDirectory('schedule-worker-');
        $plannerOwner = $this->createMock(PlannerOwnerInterface::class);
        $plannerOwner->method('ownerToken')->willReturn('planner-owner');
        $plannerOwner->method('stateDirectory')->willReturn($plannerState);
        $workerConfiguration = $this->configuration(withTask: true);
        $workerSource = new WorkerRuntimeConfigurationSource($workerConfiguration);
        $workerLedger = $this->createMock(ExecutionLedgerInterface::class);
        $workerLedgerLocator = $this->createMock(ExecutionLedgerLocatorInterface::class);
        $workerLedgerLocator->expects(self::exactly(2))->method('resolve')->with('worker-ledger')->willReturn($workerLedger);
        $workerCommand = $workerConfiguration->task('task')->command;
        $workerTask = $workerConfiguration->tasks['task'];
        $capability = new ExecutionWorkerCapability(
            new ExecutionOccurrenceKey('default', 'task', 'occurrence'),
            'attempt',
            'run',
            'execution',
            1,
            'fence',
            $workerConfiguration->fingerprint(),
            $workerConfiguration->taskFingerprint($workerTask),
            'worker-ledger',
            'worker-runtime',
            'revision',
            $workerConfiguration->taskPolicyProjection($workerTask),
        );
        $record = new ExecutionLedgerRecord(
            $capability->occurrence,
            new ExecutionIdentity('default', 'task', 'occurrence', 'attempt', 'run', 'execution'),
            1,
            new ExecutionFence(1, 'fence'),
            'launch-required',
            'dispatch',
            $capability->configurationFingerprint,
            $workerConfiguration->taskPolicyProjection($workerTask),
            metadata: ['sourceId' => 'source', 'sourceRevision' => 'revision'],
        );
        $workerLedger->method('observe')->willReturn($record);
        $workerLedger->expects(self::once())->method('claim')->willReturn(ExecutionClaimResult::accepted($record));
        $workerLedger->expects(self::once())->method('recordOutcome')->willReturn(ExecutionTerminalResult::accepted($record));
        $process = $this->createMock(ProcessHandleInterface::class);
        $process->method('isRunning')->willReturn(false);
        $process->method('isQuiescent')->willReturn(true);
        $process->method('poll')->willReturn(new ProcessResult(0));
        $processLauncher = $this->createMock(ProcessLauncherInterface::class);
        $processLauncher->expects(self::once())->method('launch')->willReturn(new ProcessLaunchResult($process, 0.0));
        $monotonicClock = $this->createMock(MonotonicClockInterface::class);
        $monotonicClock->method('now')->willReturn(0.0);
        try {
            $plannerInput = $this->plannerInput(
                $this->createMock(ExecutionOccurrenceCheckpointInterface::class),
                $plannerState,
                $plannerOwner,
            );
            $workerInput = new WorkerCompositionInput(
                configurationPath: 'worker-config',
                stateDirectory: $workerState,
                source: $workerSource,
                ledgerLocator: $workerLedgerLocator,
                wallClock: $this->clock(),
                monotonicClock: $monotonicClock,
                processLauncher: $processLauncher,
            );

            $planner = (new SchedulerFactory())->createPlanner($plannerInput);
            $worker = (new SchedulerFactory())->createFreshWorker($workerInput);

            self::assertNotSame($plannerState, $workerState);
            self::assertNotSame($planner, $worker);
            self::assertInstanceOf(WorkerAdapter::class, $worker);
            $result = $worker->invoke(
                ['run', 'task', '--config', 'worker-config', '--source', 'worker-source', '--state', $workerState],
                $capability->encode(),
            );
            self::assertSame(ExecutionWorkerInvocationResult::COMMITTED_SUCCEEDED, $result->status);
            self::assertSame('task', $worker->launchMetadata()?->task);
        } finally {
            $this->removeDirectory($plannerState);
            $this->removeDirectory($workerState);
        }
    }

    public function testCompositionInputsKeepDynamicShellVariables(): void
    {
        $stateDirectory = $this->temporaryDirectory('schedule-planner-');

        try {
            $planner = $this->plannerInput(
                $this->createMock(ExecutionOccurrenceCheckpointInterface::class),
                $stateDirectory,
            );

            self::assertSame(['NAME' => 'value'], $planner->source?->prepare($this->runtimeFactory())->task('task')->command->variables);
        } finally {
            $this->removeDirectory($stateDirectory);
        }
    }

    private function plannerInput(
        ExecutionOccurrenceCheckpointInterface $checkpoint,
        string $stateDirectory,
        ?PlannerOwnerInterface $owner = null,
    ): PlannerCompositionInput {
        $source = new WorkerRuntimeConfigurationSource($this->configuration(withTask: true));
        if ($owner === null) {
            $owner = $this->createMock(PlannerOwnerInterface::class);
            $owner->method('ownerToken')->willReturn('planner-owner');
            $owner->method('stateDirectory')->willReturn($stateDirectory);
        }

        return new PlannerCompositionInput(
            configurationPath: 'planner-config',
            stateDirectory: $stateDirectory,
            workerExecutable: '/bin/scheduler',
            source: $source,
            owner: $owner,
            ledger: $this->createMock(ExecutionLedgerInterface::class),
            occurrenceCheckpoint: $checkpoint,
            wallClock: $this->clock(),
            monotonicClock: $this->createMock(MonotonicClockInterface::class),
            waiter: $this->createMock(PollWaiterInterface::class),
            workerLauncher: $this->createMock(ExecutionWorkerLauncherInterface::class),
        );
    }

    private function configuration(bool $withTask = false): PreparedConfiguration
    {
        return new PreparedConfiguration(
            tasks: $withTask ? [new TaskDefinition(new TaskId('task'), new ShellCommand('/bin/true', [], ['NAME' => 'value']), ExecutionMode::ASYNC, trigger: new TriggerDefinition('periodic', ['type' => 'periodic', 'seconds' => 60]))] : [],
            sourceId: 'source',
            sourceRevision: 'revision',
            commandExecutorMap: new CommandExecutorMap(),
            containerProvider: new ContainerProvider(),
        );
    }

    private function runtimeFactory(): ProcessLocalCommandExecutorRuntimeFactory
    {
        return new ProcessLocalCommandExecutorRuntimeFactory(new PreparedShellCommandRuntimeFactory(
            $this->createMock(ProcessLauncherInterface::class),
            $this->createMock(MonotonicClockInterface::class),
            $this->createMock(PollWaiterInterface::class),
        ));
    }

    private function clock(): ClockInterface
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('@1700000000'));
        return $clock;
    }

    private function temporaryDirectory(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        self::assertNotFalse($path);
        unlink($path);
        mkdir($path);
        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        self::assertNotFalse($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }

        rmdir($path);
    }
}

final class WorkerRuntimeConfigurationSource implements RuntimeConfigurationSourceInterface
{
    public function __construct(private readonly PreparedConfiguration $configuration) {}

    public function sourceId(): string
    {
        return 'source';
    }

    public function revision(): string
    {
        return 'revision';
    }

    public function prepare(CommandExecutorRuntimeFactory $runtimeFactory): PreparedConfiguration
    {
        return $this->configuration;
    }
}
