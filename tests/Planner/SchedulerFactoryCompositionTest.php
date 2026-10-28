<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Planner;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Yiisoft\Scheduler\Binary\{WorkerAdapter, WorkerCompositionInput};
use Yiisoft\Scheduler\Callable\ContainerProvider;
use Yiisoft\Scheduler\Config\{PreparedConfiguration, RuntimeConfigurationSourceInterface, TaskDefinition, TriggerDefinition};
use Yiisoft\Scheduler\Executor\{CommandExecutorMap, CommandExecutorRuntimeFactory, ProcessLocalCommandExecutorRuntimeFactory, ShellCommand};
use Yiisoft\Scheduler\Integration\Async\PreparedShellCommandRuntimeFactory;
use Yiisoft\Scheduler\Integration\Async\{ExecutionAdmission, ExecutionClaimResult, ExecutionCommandScope, ExecutionDispatchReservation, ExecutionDispatchResult, ExecutionFence, ExecutionIdentity, ExecutionLaunchDescriptor, ExecutionLaunchObservation, ExecutionLedgerCommandInterface, ExecutionLedgerLocatorInterface, ExecutionLedgerQueryInterface, ExecutionLedgerRecord, ExecutionLedgerRecoveryPage, ExecutionLedgerState, ExecutionOccurrenceCheckpointInterface, ExecutionOccurrenceKey, ExecutionProcessClosure, ExecutionProcessIdentity, ExecutionStartObservation, ExecutionTerminalResult, ExecutionWorkerCapability, ExecutionWorkerInvocationResult, MonotonicClockInterface, PollWaiterInterface};
use Yiisoft\Scheduler\Planner\{PlannerCompositionInput, PlannerOwnerInterface, SourcePlannerRuntime};
use Yiisoft\Scheduler\SchedulerFactory;
use Yiisoft\Scheduler\ExecutionMode;
use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerLauncherInterface;
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessHandleInterface, ProcessIdentity as LauncherProcessIdentity, ProcessLaunchResult, ProcessLauncherInterface, ProcessResult};
use Yiisoft\Scheduler\Task\TaskId;

use function extension_loaded;

use const DIRECTORY_SEPARATOR;
use const PHP_INT_MAX;

final class SchedulerFactoryCompositionTest extends TestCase
{
    public function testPlannerCompositionUsesTheSuppliedOccurrenceCheckpointDuringTick(): void
    {
        $stateDirectory = $this->temporaryDirectory('schedule-planner-');
        $checkpoint = $this->createMock(ExecutionOccurrenceCheckpointInterface::class);
        $checkpoint->expects(self::exactly(2))->method('read')->with('task')->willReturnOnConsecutiveCalls(null, 't1700000000');
        $checkpoint->expects(self::once())->method('initialize')->with('task', 't1700000000');
        $command = $this->createMock(ExecutionLedgerCommandInterface::class);
        $command->method('admit')->willReturnCallback(static fn(ExecutionLedgerRecord $record): ExecutionAdmission => new ExecutionAdmission($record, true));
        $command->method('reserveDispatch')->willReturn(ExecutionDispatchResult::rejected('fixture'));

        try {
            $input = $this->plannerInputWithPorts($checkpoint, $stateDirectory, null, $command, $this->queryMock());
            $planner = (new SchedulerFactory())->createPlanner($input);

            self::assertInstanceOf(SourcePlannerRuntime::class, $planner);
            self::assertSame(0, $planner->tick()->admitted);
        } finally {
            $this->removeDirectory($stateDirectory);
        }
    }

    public function testPlannerCompositionRejectsCommandWithoutQueryPort(): void
    {
        $stateDirectory = $this->temporaryDirectory('schedule-planner-');
        try {
            $input = $this->plannerInputWithPorts(
                $this->createMock(ExecutionOccurrenceCheckpointInterface::class),
                $stateDirectory,
                null,
                $this->createMock(ExecutionLedgerCommandInterface::class),
                null,
            );

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('command and query ports must be supplied together');
            (new SchedulerFactory())->createPlanner($input);
        } finally {
            $this->removeDirectory($stateDirectory);
        }
    }

    public function testPlannerCompositionRejectsQueryWithoutCommandPort(): void
    {
        $stateDirectory = $this->temporaryDirectory('schedule-planner-');
        try {
            $input = $this->plannerInputWithPorts(
                $this->createMock(ExecutionOccurrenceCheckpointInterface::class),
                $stateDirectory,
                null,
                null,
                $this->queryMock(),
            );

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('command and query ports must be supplied together');
            (new SchedulerFactory())->createPlanner($input);
        } finally {
            $this->removeDirectory($stateDirectory);
        }
    }

    public function testPlannerCompositionUsesDefaultLedgerWhenNoCustomPortsAreProvided(): void
    {
        $stateDirectory = $this->temporaryDirectory('schedule-planner-');
        if (!extension_loaded('pdo_sqlite') || !extension_loaded('mbstring') || !class_exists('Yiisoft\\Db\\Sqlite\\Connection')) {
            self::markTestSkipped('SQLite ledger dependencies are not installed.');
        }
        $checkpoint = $this->createMock(ExecutionOccurrenceCheckpointInterface::class);
        $checkpoint->expects(self::once())->method('read')->with('task')->willReturn(null);
        $checkpoint->expects(self::once())->method('initialize')->with('task', 't1700000000');

        try {
            $planner = (new SchedulerFactory())->createPlanner($this->plannerInputWithPorts(
                $checkpoint,
                $stateDirectory,
                null,
                null,
                null,
            ));

            self::assertInstanceOf(SourcePlannerRuntime::class, $planner);
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
        $workerLedger = $this->createMockForIntersectionOfInterfaces([ExecutionLedgerCommandInterface::class, ExecutionLedgerQueryInterface::class]);
        $workerLedgerLocator = $this->createMock(ExecutionLedgerLocatorInterface::class);
        $workerLedgerLocator->expects(self::exactly(2))->method('resolve')->with('worker-ledger')->willReturn($workerLedger);
        $workerCommand = $workerConfiguration->task('task')->command;
        $workerTask = $workerConfiguration->tasks['task'];
        $capability = new ExecutionWorkerCapability(
            new ExecutionOccurrenceKey('task', 'occurrence'),
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
            launchDescriptor: new ExecutionLaunchDescriptor('execution', 1, 'nonce', occurrence: new ExecutionOccurrenceKey('task', 'occurrence')),
            permissionId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        );
        $processIdentity = new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id');
        $record = new ExecutionLedgerRecord(
            $capability->occurrence,
            new ExecutionIdentity('task', 'occurrence', 'attempt', 'run', 'execution'),
            1,
            new ExecutionFence(1, 'fence'),
            ExecutionLedgerState::NEEDS_RESOLUTION,
            $capability->configurationFingerprint,
            $workerConfiguration->taskPolicyProjection($workerTask),
            metadata: ['sourceId' => 'source', 'sourceRevision' => 'revision'],
            dispatchReservation: new ExecutionDispatchReservation('dispatcher', 0, PHP_INT_MAX, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ExecutionDispatchReservation::OBSERVED),
            launchObservation: new ExecutionLaunchObservation(123, null, 'complete', 0, ExecutionProcessClosure::confirmed(0, 'closed'), processIdentity: $processIdentity, startObservation: new ExecutionStartObservation(1700000000, 123456789)),
            recoveryFence: $capability->recoveryFence,
            launchDescriptor: $capability->launchDescriptor,
            sourceIdentity: 'source',
            sourceRevision: 'revision',
            processIdentity: $processIdentity,
            startAnchorIdentity: $processIdentity,
            startObservation: new ExecutionStartObservation(1700000000, 123456789),
            attemptStartObservation: new ExecutionStartObservation(1700000000, 123456789),
            recoveryAge: 180.0,
            recoveryDeadline: 1880000000,
        );
        $workerLedger->method('observe')->willReturn($record);
        $workerLedger->expects(self::once())->method('claim')->willReturn(ExecutionClaimResult::accepted($record));
        $workerLedger->expects(self::once())->method('recordOutcome')->willReturn(ExecutionTerminalResult::accepted($record));
        $workerLedger->expects(self::once())->method('prepareCommandUnderLock')->willReturnCallback(static function (...$arguments) use ($record): ExecutionDispatchResult {
            return ExecutionDispatchResult::accepted($record->transition(commandScope: new ExecutionCommandScope(
                $arguments[5],
                new ExecutionDispatchReservation('fixture-command', $arguments[6], PHP_INT_MAX, str_repeat('a', 32)),
            )));
        });
        $workerLedger->expects(self::once())->method('launchCommandUnderLock')->willReturnCallback(static function (...$arguments) use ($record): ExecutionDispatchResult {
            ($arguments[7])();
            return ExecutionDispatchResult::accepted($record);
        });
        $workerLedger->expects(self::once())->method('observeCommandUnderLock')->willReturnCallback(static function (...$arguments) use ($record): ExecutionDispatchResult {
            $observation = $arguments[6];
            self::assertTrue($observation->closure?->isConfirmed());
            self::assertSame(0, $observation->closure?->exitCode);
            return ExecutionDispatchResult::accepted($record);
        });
        $process = $this->createMock(ProcessHandleInterface::class);
        $process->method('isRunning')->willReturn(false);
        $process->method('isQuiescent')->willReturn(true);
        $process->method('poll')->willReturn(new ProcessResult(0));
        $processLauncher = $this->createMock(ProcessLauncherInterface::class);
        $processLauncher->expects(self::once())->method('launch')->willReturn(new ProcessLaunchResult($process, 0.0, 1700000000000000, new LauncherProcessIdentity(123, 123, 123, 'ticks', 'boot', $capability->launchDescriptor)));
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
        return $this->plannerInputWithPorts(
            $checkpoint,
            $stateDirectory,
            $owner,
            $this->createMock(ExecutionLedgerCommandInterface::class),
            $this->queryMock(),
        );
    }

    private function plannerInputWithPorts(
        ExecutionOccurrenceCheckpointInterface $checkpoint,
        string $stateDirectory,
        ?PlannerOwnerInterface $owner,
        ?ExecutionLedgerCommandInterface $command,
        ?ExecutionLedgerQueryInterface $query,
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
            command: $command,
            query: $query,
            occurrenceCheckpoint: $checkpoint,
            wallClock: $this->clock(),
            monotonicClock: $this->createMock(MonotonicClockInterface::class),
            waiter: $this->createMock(PollWaiterInterface::class),
            workerLauncher: $this->createMock(ExecutionWorkerLauncherInterface::class),
        );
    }

    private function queryMock(): ExecutionLedgerQueryInterface
    {
        $query = $this->createMock(ExecutionLedgerQueryInterface::class);
        $query->method('recoveryPage')->willReturn(new ExecutionLedgerRecoveryPage([], null));
        return $query;
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
