<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Recovery;

use Yiisoft\Scheduler\Tests\Support\SQLiteLedgerFixture;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yiisoft\Scheduler\Integration\Async\{ExecutionDispatchReservation, ExecutionFence, ExecutionIdentity, ExecutionLaunchDescriptor, ExecutionLaunchObservation, ExecutionLedgerRecord, ExecutionLedgerState, ExecutionOccurrenceKey, ExecutionOutcome, ExecutionProcessClosure, ExecutionProcessIdentity, ExecutionRecoveryFence, ExecutionStartObservation, ExecutionWorkerCapability, ExecutionWorkerLaunchRequest, MonotonicClockInterface, PollWaiterInterface, WorkerLifecyclePolicy};
use Yiisoft\Scheduler\Integration\Async\Store\SQLiteExecutionLedger;
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessHandleInterface, ProcessIdentity, ProcessLaunchResult, ProcessLookupResult, ProcessResult, ProcessScopeProof};
use Yiisoft\Scheduler\Integration\Async\Recovery\RecoveryProcessObserverInterface;
use Yiisoft\Scheduler\Planner\{LedgerCommandScopeProcessResolver, ParentLifecycleSupervisor};

use function getmypid;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use function count;

/** Durable integration contract for a worker's independently owned command. */
final class NestedCommandOwnershipTest extends TestCase
{
    private string $directory = '';
    private static int $sequence = 0;

    protected function setUp(): void
    {
        do {
            $this->directory = sys_get_temp_dir() . '/schedule-nested-command-' . getmypid() . '-' . self::$sequence++;
        } while (is_dir($this->directory));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            if (is_file($path = (string) $path)) {
                unlink($path);
            }
        }
        @rmdir($this->directory);
    }

    public function testLiveCommandKeepsClaimedRootOwnedAfterWorkerLoss(): void
    {
        [$ledger, $record, $root, $recovery] = $this->claimed();
        $command = $this->launchCommand($ledger, $record, $root, $recovery);

        self::assertSame(ExecutionLedgerState::CLAIMED, $ledger->observe($record->occurrence)?->state);
        self::assertSame(1, count($ledger->ownershipHoldingExecutions('source')));
        self::assertEquals($command, $ledger->observe($record->occurrence)?->commandScope?->descriptor);
    }

    public function testParentSupervisionCrossesIntoVerifiedCommandScope(): void
    {
        [$ledger, $record, $root, $recovery] = $this->claimed();
        $command = $this->launchCommand($ledger, $record, $root, $recovery);
        $persisted = $ledger->observe($record->occurrence);
        $permission = $persisted?->dispatchReservation?->permissionId;
        self::assertNotNull($permission);

        $capability = new ExecutionWorkerCapability(
            $record->occurrence,
            'attempt',
            'run',
            $root->executionId,
            $root->generation,
            $record->fence->token,
            'manifest',
            'task-version',
            'ledger://test',
            'runtime://test',
            'revision',
            $record->taskSnapshot,
            $recovery,
            $root,
            $permission,
        );
        $request = new ExecutionWorkerLaunchRequest($capability, new WorkerLifecyclePolicy(1, 2), 0);
        $rootHandle = new NestedOwnershipHandle($root, 101);
        $commandHandle = new NestedOwnershipHandle($command, 202);
        $observer = new NestedCommandObserver($command, $commandHandle);
        $clock = new NestedOwnershipClock();
        $supervisor = new ParentLifecycleSupervisor(
            $clock,
            new class implements PollWaiterInterface {
                public function wait(float $seconds): void {}
            },
            new LedgerCommandScopeProcessResolver($ledger, $observer),
        );
        $rootIdentity = new ProcessIdentity(101, 101, 101, 'root-ticks', 'boot', $root);
        $supervisor->retain($request, new ProcessLaunchResult($rootHandle, 0, identity: $rootIdentity));

        $clock->time = 1;
        $supervisor->poll();
        $clock->time = 2;
        $supervisor->poll();
        $supervisor->poll();

        self::assertSame(1, $rootHandle->soft);
        self::assertSame(1, $rootHandle->hard);
        self::assertSame(1, $commandHandle->soft);
        self::assertSame(1, $commandHandle->hard);

        $observer->closed = true;
        self::assertNull((new LedgerCommandScopeProcessResolver($ledger, $observer))->resolve(
            array_values($supervisor->retained())[0],
        ));
        self::assertSame(1, $commandHandle->soft);
        self::assertSame(1, $commandHandle->hard);
    }

    public function testRetainedWorkerWithStaleCapabilityFactsCannotResolveLiveCommand(): void
    {
        [$ledger, $record, $root, $recovery] = $this->claimed();
        $command = $this->launchCommand($ledger, $record, $root, $recovery);
        $observer = new NestedCommandObserver($command, new NestedOwnershipHandle($command, 202));
        $resolver = new LedgerCommandScopeProcessResolver($ledger, $observer);
        $rootIdentity = new ProcessIdentity(101, 101, 101, 'root-ticks', 'boot', $root);
        $policy = $record->taskSnapshot;

        $capabilities = [
            new ExecutionWorkerCapability($record->occurrence, 'stale-attempt', 'run', $root->executionId, 1, 'fence-1', 'manifest', 'task-version', 'ledger://test', 'runtime://test', 'revision', $policy, $recovery, $root, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
            new ExecutionWorkerCapability($record->occurrence, 'attempt', 'run', $root->executionId, 1, 'stale-fence', 'manifest', 'task-version', 'ledger://test', 'runtime://test', 'revision', $policy, $recovery, $root, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),
            new ExecutionWorkerCapability($record->occurrence, 'attempt', 'run', $root->executionId, 1, 'fence-1', 'manifest', 'task-version', 'ledger://test', 'runtime://test', 'revision', $policy, new ExecutionRecoveryFence('stale-recovery', 1), $root, 'cccccccccccccccccccccccccccccccc'),
            new ExecutionWorkerCapability($record->occurrence, 'attempt', 'run', $root->executionId, 1, 'fence-1', 'manifest', 'task-version', 'ledger://test', 'runtime://test', 'stale-revision', array_replace($policy, ['source' => ['stale-source', 'stale-revision']]), $recovery, $root, 'dddddddddddddddddddddddddddddddd'),
        ];

        foreach ($capabilities as $capability) {
            $request = new ExecutionWorkerLaunchRequest($capability, new WorkerLifecyclePolicy(1, 2), 0);
            $retained = new ParentLifecycleSupervisor(
                new NestedOwnershipClock(),
                new class implements PollWaiterInterface {
                    public function wait(float $seconds): void {}
                },
                $resolver,
            );
            $retained->retain($request, new ProcessLaunchResult(new NestedOwnershipHandle($root, 101), 0, identity: $rootIdentity));
            self::assertNull($resolver->resolve(array_values($retained->retained())[0]));
        }

        self::assertSame(0, $observer->lookups);
    }

    public function testAmbiguousCommandReceiptIsNotBootstrapableOrReclaimable(): void
    {
        [$ledger, $record, $root, $recovery] = $this->claimed();
        $prepared = $ledger->prepareCommandUnderLock($record->occurrence, $record->fence, $recovery, 'source', $root, $this->command($record, $root), 12, 12_000_000);
        self::assertSame('accepted', $prepared->status);
        $scope = $prepared->record?->commandScope;
        self::assertNotNull($scope);
        $ambiguous = $ledger->launchCommandUnderLock($record->occurrence, $record->fence, $recovery, 'source', $root, $scope, 12, static function (): never {
            throw new RuntimeException('receipt was not published');
        }, 12_000_000);
        self::assertSame('ambiguous', $ambiguous->status);
        self::assertSame(ExecutionDispatchReservation::AMBIGUOUS, $ledger->observe($record->occurrence)?->commandScope?->reservation->launchPhase);
        self::assertSame(1, count($ledger->ownershipHoldingExecutions('source')));
    }

    public function testTerminalRootRetainsLiveCommandAndClosedCommandReleasesNestedScope(): void
    {
        [$ledger, $record, $root, $recovery] = $this->claimed();
        $command = $this->launchCommand($ledger, $record, $root, $recovery);
        self::assertSame('accepted', $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 13)->status);
        self::assertCount(1, $ledger->ownershipHoldingExecutions('source'));

        $closed = $this->closedObservation($command);
        self::assertSame('accepted', $ledger->observeCommandUnderLock($record->occurrence, $record->fence, $recovery, 'source', $root, $command, $closed)->status);
        self::assertCount(1, $ledger->ownershipHoldingExecutions('source'));
        self::assertSame('accepted', $ledger->observeLaunch($record->occurrence, $record->fence, $recovery, $this->closedRootObservation($root), 'source')->status);
        self::assertCount(0, $ledger->ownershipHoldingExecutions('source'));
    }

    public function testLiveRootWithClosedCommandRetainsRootAndAggregateClosureAllowsRotation(): void
    {
        [$ledger, $record, $root, $recovery] = $this->claimed();
        $command = $this->launchCommand($ledger, $record, $root, $recovery);
        self::assertSame('accepted', $ledger->observeCommandUnderLock($record->occurrence, $record->fence, $recovery, 'source', $root, $command, $this->closedObservation($command))->status);
        self::assertCount(1, $ledger->ownershipHoldingExecutions('source'));

        self::assertSame('accepted', $ledger->observeLaunch($record->occurrence, $record->fence, $recovery, $this->closedRootObservation($root), 'source')->status);
        self::assertSame('accepted', $ledger->revoke($record->occurrence, $recovery, 15_000_000, $record->fence, 'source')->status);
        self::assertCount(0, $ledger->ownershipHoldingExecutions('source'));
        $rotated = $ledger->allocateGeneration($record->occurrence, $recovery, new ExecutionFence(2, 'fence-2'), 16_000_000, $record->fence, 'source');
        self::assertSame('allocated', $rotated->status);
        self::assertSame(2, $rotated->record?->fence->generation);
    }

    private function claimed(): array
    {
        $ledger = SQLiteLedgerFixture::ledger($this->directory);
        $occurrence = new ExecutionOccurrenceKey('task', 'occurrence');
        $fence = new ExecutionFence(1, 'fence-1');
        $recovery = new ExecutionRecoveryFence('recovery-1', 1);
        $record = new ExecutionLedgerRecord($occurrence, new ExecutionIdentity('task', 'occurrence', 'attempt', 'run', 'execution-1'), 1, $fence, ExecutionLedgerState::LAUNCH_REQUIRED, 'manifest', ['schema' => 'dev', 'source' => ['source', 'revision'], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => null, 'recoveryMaxAge' => 180.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]], createdAt: 1, updatedAt: 1, recoveryFence: $recovery, sourceIdentity: 'source', sourceRevision: 'revision');
        $ledger->admit($record);
        $reservation = new ExecutionDispatchReservation('dispatcher', 10, 100);
        $ledger->reserveDispatch($occurrence, $fence, $reservation);
        $root = new ExecutionLaunchDescriptor('execution-1', 1, 'root', occurrence: $occurrence);
        $ledger->recordLaunchDescriptor($occurrence, $fence, $recovery, $root, 'source');
        $identity = new ExecutionProcessIdentity(101, 101, 101, 'root-ticks', 'boot');
        $start = new ExecutionStartObservation(10_000_000, 10);
        $ledger->launchUnderLock($occurrence, $fence, $recovery, 'source', $root, $reservation->permissionId, 10, static fn(): ExecutionLaunchObservation => new ExecutionLaunchObservation(101, null, 'started', 10, processIdentity: $identity, startObservation: $start), 10_000_000);
        $ledger->claim($occurrence, $fence, 11, 100, $root, $reservation->permissionId);
        return [$ledger, $record, $root, $recovery];
    }

    private function command(ExecutionLedgerRecord $record, ExecutionLaunchDescriptor $root): ExecutionLaunchDescriptor
    {
        return new ExecutionLaunchDescriptor('execution-1', 1, 'command', rootDescriptor: $root, occurrence: $record->occurrence, role: ExecutionLaunchDescriptor::ROLE_COMMAND);
    }

    private function launchCommand(SQLiteExecutionLedger $ledger, ExecutionLedgerRecord $record, ExecutionLaunchDescriptor $root, ExecutionRecoveryFence $recovery): ExecutionLaunchDescriptor
    {
        $descriptor = $this->command($record, $root);
        $prepared = $ledger->prepareCommandUnderLock($record->occurrence, $record->fence, $recovery, 'source', $root, $descriptor, 12, 12_000_000);
        self::assertSame('accepted', $prepared->status);
        $scope = $prepared->record?->commandScope;
        self::assertNotNull($scope);
        $identity = new ExecutionProcessIdentity(202, 202, 202, 'command-ticks', 'boot');
        $result = $ledger->launchCommandUnderLock($record->occurrence, $record->fence, $recovery, 'source', $root, $scope, 12, static fn(): ExecutionLaunchObservation => new ExecutionLaunchObservation(202, null, 'started', 12, processIdentity: $identity, startObservation: new ExecutionStartObservation(12_000_000, 12)), 12_000_000);
        self::assertSame('accepted', $result->status);
        return $descriptor;
    }

    private function closedObservation(ExecutionLaunchDescriptor $descriptor): ExecutionLaunchObservation
    {
        $identity = new ExecutionProcessIdentity($descriptor->role === ExecutionLaunchDescriptor::ROLE_COMMAND ? 202 : 101, $descriptor->role === ExecutionLaunchDescriptor::ROLE_COMMAND ? 202 : 101, $descriptor->role === ExecutionLaunchDescriptor::ROLE_COMMAND ? 202 : 101, $descriptor->role === ExecutionLaunchDescriptor::ROLE_COMMAND ? 'command-ticks' : 'root-ticks', 'boot');
        $start = new ExecutionStartObservation($descriptor->role === ExecutionLaunchDescriptor::ROLE_COMMAND ? 12_000_000 : 10_000_000, $descriptor->role === ExecutionLaunchDescriptor::ROLE_COMMAND ? 12 : 10);
        return new ExecutionLaunchObservation($identity->pid, null, 'closed', 14, ExecutionProcessClosure::confirmed(0, 'closed'), processIdentity: $identity, startObservation: $start);
    }

    private function closedRootObservation(ExecutionLaunchDescriptor $root): ExecutionLaunchObservation
    {
        return $this->closedObservation($root);
    }
}

final class NestedOwnershipClock implements MonotonicClockInterface
{
    public float $time = 0;

    public function now(): float
    {
        return $this->time;
    }
}

final class NestedOwnershipHandle implements ProcessHandleInterface
{
    public int $soft = 0;
    public int $hard = 0;
    private readonly ProcessIdentity $identity;

    public function __construct(private readonly ExecutionLaunchDescriptor $descriptor, int $pid)
    {
        $this->identity = new ProcessIdentity($pid, $pid, $pid, $descriptor->role . '-ticks', 'boot', $descriptor);
    }

    public function scopeProof(): ProcessScopeProof
    {
        return ProcessScopeProof::liveFromLookup($this->descriptor, $this->identity);
    }

    public function isRunning(): bool
    {
        return true;
    }

    public function isQuiescent(): bool
    {
        return false;
    }

    public function poll(): ?ProcessResult
    {
        return null;
    }

    public function terminateSoft(): void
    {
        ++$this->soft;
    }

    public function terminateHard(): void
    {
        ++$this->hard;
    }
}

final class NestedCommandObserver implements RecoveryProcessObserverInterface
{
    public bool $closed = false;
    public int $lookups = 0;

    public function __construct(private readonly ExecutionLaunchDescriptor $descriptor, private readonly ProcessHandleInterface $handle) {}

    public function lookup(ExecutionLaunchDescriptor $descriptor, ?ExecutionProcessIdentity $expected = null): ProcessLookupResult
    {
        ++$this->lookups;
        $identity = new ProcessIdentity(202, 202, 202, 'command-ticks', 'boot', $this->descriptor);
        if ($this->closed || !$descriptor->equals($this->descriptor) || $expected === null) {
            return new ProcessLookupResult(ProcessLookupResult::CLOSED, proof: ProcessScopeProof::closedTerminalFromLookup($this->descriptor, $identity));
        }
        $identity = new ProcessIdentity($expected->pid, $expected->processGroup, $expected->session, $expected->startTicks, $expected->bootId, $descriptor);
        return new ProcessLookupResult(ProcessLookupResult::MATCH, $identity, $this->handle, ProcessScopeProof::liveFromLookup($descriptor, $identity));
    }
}
