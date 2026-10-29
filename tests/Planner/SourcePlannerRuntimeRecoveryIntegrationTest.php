<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Planner;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use stdClass;
use Yiisoft\Scheduler\Config\{ConfigurationSourceInterface, PreparedConfiguration, TaskDefinition, TriggerDefinition};
use Yiisoft\Scheduler\ExecutionMode;
use Yiisoft\Scheduler\Executor\ShellCommand;
use Yiisoft\Scheduler\Integration\Async\{ExecutionDispatchReservation, ExecutionDispatchResult, ExecutionFence, ExecutionIdentity, ExecutionLedgerCommandInterface, ExecutionLedgerQueryInterface, ExecutionLedgerRecord, ExecutionLedgerRecoveryPage, ExecutionLedgerState, ExecutionLaunchDescriptor, ExecutionLaunchObservation, ExecutionOccurrenceCheckpointInterface, ExecutionOccurrenceKey, ExecutionOutcome, ExecutionProcessClosure, ExecutionProcessIdentity, ExecutionRecoveryFence, ExecutionStartObservation, ExecutionWorkerCapability, ExecutionWorkerLaunchHandle, ExecutionWorkerLaunchReceipt, ExecutionWorkerLaunchRequest, ExecutionWorkerLauncherInterface, ExecutionWorkerRuntimeComposer, ExecutionWorkerRuntimeFactoryInterface, ExecutionWorkerRuntimeInterface, MonotonicClockInterface, PollWaiterInterface};
use Yiisoft\Scheduler\Integration\Async\Process\{AttachedProcessHandle, ProcessHandleInterface, ProcessIdentity, ProcessLaunchResult, ProcessLookupResult, ProcessResult, ProcessScopeProof};
use Yiisoft\Db\Sqlite\{Connection, Driver};
use Yiisoft\Scheduler\Integration\Async\Store\SQLiteExecutionLedger;
use Yiisoft\Scheduler\Task\TaskId;
use Yiisoft\Scheduler\Integration\Async\Recovery\RecoveryProcessObserverInterface;
use Yiisoft\Scheduler\Planner\{ParentLifecycleSupervisor, PlannerOwnerInterface, SourcePlannerRuntime};

use function count;

/** Public control-loop coverage for source-pinned recovery. */
final class SourcePlannerRuntimeRecoveryIntegrationTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/source-planner-recovery-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->directory);
    }

    public function testPublicTicksPagePinnedRecoveryOneRecordAtATimeBeyondPassBudget(): void
    {
        $records = array_map(fn(int $number): ExecutionLedgerRecord => $this->record('task-' . $number, terminal: true), range(1, 40));
        [$ledger, $state] = $this->ledgerQueryMock($records);
        $planner = $this->planner($ledger, sourceRevision: 'revision', observer: new SourcePlannerRuntimeRecoveryObserver($records[0], noMatch: true));

        for ($i = 0; $i < count($records); ++$i) {
            $planner->tick();
        }

        self::assertSame(40, $state->recoveryPageCalls);
        self::assertSame(1, $state->limits[0]);
        self::assertSame(1, $state->limits[39]);
        self::assertCount(40, array_unique($state->returnedOccurrences));
    }

    public function testPublicRecoveryTickForwardsLiveObservationWhenTaskIsAbsentAndSourceAdmissionIsClosed(): void
    {
        $record = $this->record('removed', terminal: true);
        [$ledger] = $this->ledgerQueryMock([$record]);
        $ledger->expects(self::once())->method('observeLaunch')->willReturn(ExecutionDispatchResult::accepted($record));
        $observer = new SourcePlannerRuntimeRecoveryObserver($record, closed: true);
        $planner = $this->planner($ledger, sourceRevision: 'revision', observer: $observer, changeRevision: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Planner source version changed');
        $planner->tick();
    }

    public function testIncompleteLiveObservationPreservesUnclaimedProvenanceForClosedPreStartRepair(): void
    {
        $ledger = $this->ledger();
        $task = new TaskDefinition(new TaskId('task'), new ShellCommand('/bin/true'), ExecutionMode::ASYNC, recoveryMaxAge: 10.0, trigger: new TriggerDefinition('periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => '2020-01-01T00:00:00Z', 'until' => '2021-01-01T00:00:00Z']));
        $configuration = new PreparedConfiguration([$task], sourceId: 'source', sourceRevision: 'revision');
        $record = $this->record('task', preStart: true, taskSnapshot: $configuration->taskPolicyProjection($task), configurationFingerprint: $configuration->fingerprint());
        $descriptor = new ExecutionLaunchDescriptor('execution-task', 1, 'nonce-task', occurrence: $record->occurrence);
        $reservation = new ExecutionDispatchReservation('dispatcher', 1_700_000_000, 1_700_000_010, str_repeat('a', 32), ExecutionDispatchReservation::STARTING);
        $record = $record->transition(
            state: ExecutionLedgerState::LAUNCH_REQUESTED,
            dispatchReservation: $reservation,
            launchDescriptor: $descriptor,
        );
        $ledger->admit($record);

        $observer = new SourcePlannerRuntimeR3Observer();
        $launcher = new SourcePlannerRuntimeR3Launcher();
        $planner = $this->planner($ledger, 'revision', $observer, configuration: $configuration, launcherOverride: $launcher);

        $planner->tick();
        $afterLive = $ledger->observe($record->occurrence);
        self::assertNotNull($afterLive);
        self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $afterLive->state);
        self::assertSame($observer->identity->pid, $afterLive->processIdentity?->pid);
        self::assertSame($observer->identity->startTicks, $afterLive->processIdentity?->startTicks);
        self::assertNotNull($afterLive->launchObservation);
        self::assertNull($afterLive->launchObservation?->closure);
        self::assertNull($afterLive->startObservation);

        $observer->closed = true;
        $observer->handle->closed = true;
        $planner->poll();
        $afterClosure = $ledger->observe($record->occurrence);
        self::assertNotNull($afterClosure);
        self::assertSame($observer->identity->pid, $afterClosure->processIdentity?->pid);
        self::assertSame($observer->identity->startTicks, $afterClosure->processIdentity?->startTicks);
        self::assertTrue($afterClosure->launchObservation?->closure?->isConfirmed());
        self::assertNull($afterClosure->startObservation);

        $planner->tick();
        self::assertCount(1, $launcher->requests);
        $request = $launcher->requests[0];
        self::assertSame(1, $request->capability->generation);
        self::assertNotSame($reservation->permissionId, $request->capability->permissionId);

        $factory = new class implements ExecutionWorkerRuntimeFactoryInterface {
            public function create(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $claimedRecord, ExecutionLedgerCommandInterface $ledger): ExecutionWorkerRuntimeInterface
            {
                return new class implements ExecutionWorkerRuntimeInterface {
                    public function execute(): ExecutionOutcome
                    {
                        return new ExecutionOutcome(ExecutionOutcome::SUCCEEDED);
                    }
                };
            }
        };
        (new ExecutionWorkerRuntimeComposer($ledger, $factory))->compose(
            $request->capability,
            1_700_000_000,
            1_700_000_001,
            $request->descriptor,
            $request->capability->permissionId,
        );
        self::assertSame(ExecutionLedgerState::CLAIMED, $ledger->observe($record->occurrence)?->state);
    }

    public function testRecoveryRedispatchPublishesReplacementPermissionAcceptedByFreshWorker(): void
    {
        $task = new TaskDefinition(new TaskId('task'), new ShellCommand('/bin/true'), ExecutionMode::ASYNC, recoveryMaxAge: 10.0, trigger: new TriggerDefinition('periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => '2020-01-01T00:00:00Z', 'until' => '2021-01-01T00:00:00Z']));
        $configuration = new PreparedConfiguration([$task], sourceId: 'source', sourceRevision: 'revision');
        $record = $this->record('task', preStart: true, taskSnapshot: $configuration->taskPolicyProjection($task), configurationFingerprint: $configuration->fingerprint());
        $ledger = $this->ledger();
        $ledger->admit($record);
        $old = new ExecutionDispatchReservation('dispatcher', 1_699_999_900, 1_699_999_999, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        self::assertSame(ExecutionDispatchResult::ACCEPTED, $ledger->reserveDispatch($record->occurrence, $record->fence, $old)->status);

        $captured = [];
        $launcher = $this->createMock(ExecutionWorkerLauncherInterface::class);
        $launcher->expects(self::once())->method('launch')->willReturnCallback(function ($request) use (&$captured): ExecutionWorkerLaunchReceipt {
            $captured[] = $request;
            $handle = $this->createMock(ProcessHandleInterface::class);
            $identity = new ProcessIdentity(123, 123, 123, 'ticks', 'boot', $request->descriptor);
            return new ExecutionWorkerLaunchReceipt(
                new ExecutionWorkerLaunchHandle('recovery-launch'),
                new ProcessLaunchResult($handle, 0.0, 1_700_000_000_000_000, $identity),
            );
        });
        $planner = $this->planner($ledger, 'revision', new SourcePlannerRuntimeRecoveryObserver($record, noMatch: true), configuration: $configuration, launcherOverride: $launcher);

        $planner->tick();
        self::assertCount(1, $captured);
        $factory = new class implements ExecutionWorkerRuntimeFactoryInterface {
            public int $calls = 0;

            public function create(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $claimedRecord, ExecutionLedgerCommandInterface $ledger): ExecutionWorkerRuntimeInterface
            {
                ++$this->calls;
                return new class implements ExecutionWorkerRuntimeInterface {
                    public function execute(): ExecutionOutcome
                    {
                        return new ExecutionOutcome(ExecutionOutcome::SUCCEEDED);
                    }
                };
            }
        };
        (new ExecutionWorkerRuntimeComposer($ledger, $factory))->compose($captured[0]->capability, 1_700_000_000, 1_700_000_010, $captured[0]->descriptor, $captured[0]->capability->permissionId);
        $persisted = ($this->ledger())->observe($record->occurrence);
        self::assertNotSame($old->permissionId, $captured[0]->capability->permissionId);
        self::assertSame($persisted?->dispatchReservation?->permissionId, $captured[0]->capability->permissionId);
        self::assertSame(1, $factory->calls);
    }

    private function ledger(): SQLiteExecutionLedger
    {
        return new SQLiteExecutionLedger(new Connection(new Driver('sqlite:' . $this->directory . '/ledger.sqlite')));
    }

    private function planner(object $ledger, string $sourceRevision, ?RecoveryProcessObserverInterface $observer = null, bool $changeRevision = false, ?PreparedConfiguration $configuration = null, ?ExecutionWorkerLauncherInterface $launcherOverride = null, string $checkpointCursor = 't1700000000'): SourcePlannerRuntime
    {
        $wall = $this->createMock(ClockInterface::class);
        $wall->method('now')->willReturn(new DateTimeImmutable('@1700000000'));
        $monotonic = $this->createMock(MonotonicClockInterface::class);
        $monotonic->method('now')->willReturn(0.0);
        $waiter = $this->createMock(PollWaiterInterface::class);
        $owner = $this->createMock(PlannerOwnerInterface::class);
        $owner->method('ownerToken')->willReturn('owner');
        $owner->method('stateDirectory')->willReturn($this->directory);
        $source = $this->createMock(ConfigurationSourceInterface::class);
        $source->method('sourceId')->willReturn('source');
        if ($changeRevision) {
            $source->method('revision')->willReturnOnConsecutiveCalls($sourceRevision, 'replacement');
        } else {
            $source->method('revision')->willReturn($sourceRevision);
        }
        $checkpoint = $this->createMock(ExecutionOccurrenceCheckpointInterface::class);
        $checkpoint->method('read')->willReturn($checkpointCursor);
        $checkpoint->method('initialize')->willReturnCallback(static function (): void {});
        $launcher = $launcherOverride ?? $this->createMock(ExecutionWorkerLauncherInterface::class);
        $configuration ??= new PreparedConfiguration(
            tasks: [],
            sourceId: 'source',
            sourceRevision: 'revision',
        );
        $supervisor = new ParentLifecycleSupervisor($monotonic, $waiter);

        return new SourcePlannerRuntime($configuration, $source, $owner, $wall, $monotonic, $waiter, $launcher, $supervisor, $this->directory, $ledger, $ledger, $checkpoint, $observer);
    }

    private function ledgerQueryMock(array $records): array
    {
        $ledger = $this->createMockForIntersectionOfInterfaces([ExecutionLedgerCommandInterface::class, ExecutionLedgerQueryInterface::class]);
        $state = new stdClass();
        $state->recoveryPageCalls = 0;
        $state->limits = [];
        $state->returnedOccurrences = [];
        $ledger->method('recoveryPage')->willReturnCallback(function (string $source, ?string $cursor, int $limit) use ($state, $records): ExecutionLedgerRecoveryPage {
            ++$state->recoveryPageCalls;
            $state->limits[] = $limit;
            $offset = $cursor === null ? 0 : (int) $cursor;
            $record = $records[$offset] ?? null;
            if ($record === null) {
                return new ExecutionLedgerRecoveryPage([], null);
            }
            $state->returnedOccurrences[] = (string) $record->occurrence;
            return new ExecutionLedgerRecoveryPage([$record], $offset + 1 < count($records) ? (string) ($offset + 1) : null);
        });
        return [$ledger, $state];
    }

    private function record(string $task, bool $terminal = false, bool $preStart = false, ?array $taskSnapshot = null, string $configurationFingerprint = 'fingerprint'): ExecutionLedgerRecord
    {
        $occurrence = new ExecutionOccurrenceKey($task, 'occurrence');
        $descriptor = new ExecutionLaunchDescriptor('execution-' . $task, 1, 'nonce-' . $task, occurrence: $occurrence);
        $identity = new ExecutionProcessIdentity(7, 7, 7, 'ticks', 'boot');
        $start = new ExecutionStartObservation(1_000_000, 1);
        return new ExecutionLedgerRecord(
            $occurrence,
            new ExecutionIdentity($task, 'occurrence', 'attempt', 'run', 'execution-' . $task),
            1,
            new ExecutionFence(1, 'fence-' . $task),
            $terminal ? ExecutionLedgerState::SUCCEEDED : ($preStart ? ExecutionLedgerState::LAUNCH_REQUIRED : ExecutionLedgerState::RUNNING),
            $configurationFingerprint,
            $taskSnapshot ?? ['schema' => 'dev', 'source' => ['source', 'revision'], 'task' => ['id' => $task, 'mode' => 'async', 'timeout' => [1.0, 2.0], 'recoveryMaxAge' => 10.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]],
            createdAt: 0,
            outcome: $terminal ? new ExecutionOutcome(ExecutionOutcome::SUCCEEDED) : null,
            launchObservation: $preStart ? null : new ExecutionLaunchObservation(7, null, $terminal ? 'closed' : 'started', 1, $terminal ? ExecutionProcessClosure::confirmed(0, 'closed') : null, processIdentity: $identity, startObservation: $start),
            recoveryFence: new ExecutionRecoveryFence('recovery-' . $task),
            sourceIdentity: 'source',
            sourceRevision: 'revision',
            launchDescriptor: $preStart ? null : $descriptor,
            processIdentity: $preStart ? null : $identity,
            startAnchorIdentity: $preStart ? null : $identity,
            startObservation: $preStart ? null : $start,
            attemptStartObservation: $preStart ? null : $start,
            recoveryAge: $preStart ? null : 10.0,
            recoveryDeadline: $preStart ? null : 11_000_000,
        );
    }
}

final class SourcePlannerRuntimeR3Observer implements RecoveryProcessObserverInterface
{
    public bool $closed = false;
    public ProcessIdentity $identity;
    public readonly SourcePlannerRuntimeR3Handle $handle;

    public function __construct()
    {
        $this->handle = new SourcePlannerRuntimeR3Handle();
    }

    public function lookup(ExecutionLaunchDescriptor $descriptor, ?ExecutionProcessIdentity $expected = null): ProcessLookupResult
    {
        $identity = new ProcessIdentity(41, 41, 41, 'r3-ticks', 'r3-boot', $descriptor);
        if ($this->closed) {
            return new ProcessLookupResult(ProcessLookupResult::CLOSED, proof: ProcessScopeProof::closedEsrchFromLookup($descriptor, $identity));
        }
        $this->identity = $identity;
        $this->handle->identity = $identity;
        return new ProcessLookupResult(ProcessLookupResult::MATCH, $identity, $this->handle, ProcessScopeProof::liveFromLookup($descriptor, $identity));
    }
}

final class SourcePlannerRuntimeR3Handle implements ProcessHandleInterface
{
    public bool $closed = false;

    public function __construct(public ?ProcessIdentity $identity = null) {}

    public function scopeProof(): ProcessScopeProof
    {
        if ($this->identity === null) {
            throw new RuntimeException('R3 handle has no observed identity.');
        }
        return $this->closed
            ? ProcessScopeProof::closedEsrchFromRetainedPoll($this->identity->descriptor, $this->identity)
            : ProcessScopeProof::liveFromRetainedPoll($this->identity->descriptor, $this->identity);
    }

    public function isRunning(): bool
    {
        return !$this->closed;
    }

    public function isQuiescent(): bool
    {
        return $this->closed;
    }

    public function poll(): ?ProcessResult
    {
        return $this->closed ? new ProcessResult(null, null, '', '', new DateTimeImmutable('@1700000000')) : null;
    }

    public function terminateSoft(): void {}

    public function terminateHard(): void {}
}

final class SourcePlannerRuntimeR3Launcher implements ExecutionWorkerLauncherInterface
{
    /** @var list<ExecutionWorkerLaunchRequest> */
    public array $requests = [];

    public function launch(ExecutionWorkerLaunchRequest $request): ExecutionWorkerLaunchReceipt
    {
        $this->requests[] = $request;
        $identity = new ProcessIdentity(42, 42, 42, 'replacement-ticks', 'r3-boot', $request->descriptor);
        return new ExecutionWorkerLaunchReceipt(
            new ExecutionWorkerLaunchHandle('r3-replacement'),
            new ProcessLaunchResult(new SourcePlannerRuntimeR3Handle($identity), 0.0, 1_700_000_000_000_000, $identity),
        );
    }
}

final class SourcePlannerRuntimeRecoveryObserver implements RecoveryProcessObserverInterface
{
    public function __construct(private readonly ExecutionLedgerRecord $record, private readonly bool $closed = false, private readonly bool $noMatch = false) {}

    public function lookup(ExecutionLaunchDescriptor $descriptor, ?ExecutionProcessIdentity $expected = null): ProcessLookupResult
    {
        $identity = new ProcessIdentity(7, 7, 7, 'ticks', 'boot', $descriptor);
        if ($this->noMatch) {
            return new ProcessLookupResult(ProcessLookupResult::NO_MATCH);
        }
        if ($this->closed) {
            return new ProcessLookupResult(ProcessLookupResult::CLOSED, proof: ProcessScopeProof::closedEsrchFromLookup($descriptor, $identity));
        }
        return new ProcessLookupResult(ProcessLookupResult::MATCH, $identity, new AttachedProcessHandle($identity), ProcessScopeProof::liveFromLookup($descriptor, $identity));
    }
}
