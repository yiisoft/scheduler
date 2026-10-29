<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Store;

use Yiisoft\Scheduler\Tests\Support\SQLiteLedgerFixture;
use Yiisoft\Scheduler\Integration\Async\Store\SQLiteExecutionLedger;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\{
    ExecutionDispatchReservation, ExecutionDispatchResult, ExecutionFence, ExecutionIdentity,
    ExecutionLaunchDescriptor, ExecutionLaunchObservation, ExecutionLedgerRecord, ExecutionLedgerState,
    ExecutionOccurrenceKey, ExecutionOutcome, ExecutionProcessClosure, ExecutionProcessIdentity,
    ExecutionRecoveryFence, ExecutionStartObservation,
};
use LogicException;
use RuntimeException;

use function getmypid;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function unlink;

final class LaunchPermissionRaceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        static $number = 0;
        do {
            $this->directory = sys_get_temp_dir() . '/schedule-launch-race-' . getmypid() . '-' . $number++;
        } while (is_dir($this->directory));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->directory);
    }

    public function testPreparedPermissionHasUniqueIdAndPhaseAndCannotBeReused(): void
    {
        $first = new ExecutionDispatchReservation('dispatcher', 10, 20);
        $second = new ExecutionDispatchReservation('dispatcher', 10, 20);
        self::assertNotSame($first->permissionId, $second->permissionId);
        self::assertSame(ExecutionDispatchReservation::PREPARED, $first->launchPhase);

        $ledger = $this->ledger();
        $record = $this->record();
        $ledger->admit($record);
        self::assertSame(ExecutionDispatchResult::ACCEPTED, $ledger->reserveDispatch($record->occurrence, $record->fence, $first)->status);
        self::assertSame(ExecutionDispatchResult::ALREADY_LAUNCHED, $ledger->reserveDispatch($record->occurrence, $record->fence, $second)->status);
    }

    public function testPreparedPermissionMayRetainOriginalRecoveryAnchorWithoutCurrentLaunchFacts(): void
    {
        $identity = new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id');
        $start = new ExecutionStartObservation(1700000000000000, 123456789);
        $reservation = new ExecutionDispatchReservation('dispatcher', 10, 20);

        $record = $this->record()->transition(
            dispatchReservation: $reservation,
            startAnchorIdentity: $identity,
            startObservation: $start,
            recoveryAge: 180.0,
            recoveryDeadline: 1700000180000000,
            updatedAt: 2,
        );

        self::assertSame(ExecutionDispatchReservation::PREPARED, $record->dispatchReservation?->launchPhase);
        self::assertEquals($identity, $record->startAnchorIdentity);
        self::assertEquals($start, $record->startObservation);
        self::assertNull($record->processIdentity);
        self::assertNull($record->attemptStartObservation);
    }

    public function testLaunchPersistsStartingBeforeCallbackAndPublishesCompleteReceiptAtomically(): void
    {
        $ledger = $this->ledger();
        $record = $this->record();
        $ledger->admit($record);
        $reservation = new ExecutionDispatchReservation('dispatcher', 10, 20);
        $descriptor = $this->descriptor($record);
        $ledger->reserveDispatch($record->occurrence, $record->fence, $reservation);
        $ledger->recordLaunchDescriptor($record->occurrence, $record->fence, $this->recovery(), $descriptor, 'source-1');
        $called = false;
        $identity = new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id');
        $start = new ExecutionStartObservation(1700000000000000, 123456789);

        $result = $ledger->launchUnderLock($record->occurrence, $record->fence, $this->recovery(), 'source-1', $descriptor, $reservation->permissionId, 11, function () use (&$called, $identity, $start): ExecutionLaunchObservation {
            $called = true;
            return new ExecutionLaunchObservation(123, null, 'started', 11, ExecutionProcessClosure::unknown('running'), processIdentity: $identity, startObservation: $start);
        }, nowUs: 11_000_000);

        self::assertTrue($called);
        self::assertSame(ExecutionDispatchResult::ACCEPTED, $result->status);
        self::assertSame(ExecutionDispatchReservation::OBSERVED, $result->record?->dispatchReservation?->launchPhase);
        self::assertEquals($descriptor, $result->record?->launchDescriptor);
        self::assertEquals($identity, $result->record?->startAnchorIdentity);
        self::assertEquals($start, $result->record?->startObservation);
        self::assertSame(ExecutionDispatchResult::STALE, $ledger->launchUnderLock($record->occurrence, $record->fence, $this->recovery(), 'source-1', $descriptor, $reservation->permissionId, 11, static function (): never {
            throw new LogicException('consumed permission was reused');
        }, nowUs: 11_000_000)->status);
    }

    public function testExpiredPermissionCannotStartAtItsBoundary(): void
    {
        [$ledger, $record, $reservation, $descriptor] = $this->prepared();
        $called = false;
        $result = $ledger->launchUnderLock($record->occurrence, $record->fence, $this->recovery(), 'source-1', $descriptor, $reservation->permissionId, 20, static function () use (&$called): never {
            $called = true;
            throw new LogicException('expired permission must not launch');
        }, nowUs: 20_000_000);
        self::assertSame(ExecutionDispatchResult::STALE, $result->status);
        self::assertFalse($called);
        self::assertSame(ExecutionDispatchReservation::PREPARED, $ledger->observe($record->occurrence)?->dispatchReservation?->launchPhase);
    }

    public function testCallbackFailureLeavesAmbiguousAndNeverInfersAbsence(): void
    {
        [$ledger, $record, $reservation, $descriptor] = $this->prepared();
        $result = $ledger->launchUnderLock($record->occurrence, $record->fence, $this->recovery(), 'source-1', $descriptor, $reservation->permissionId, 11, static function (): never {
            throw new RuntimeException('launch boundary failed');
        }, nowUs: 11_000_000);
        self::assertSame(ExecutionDispatchResult::AMBIGUOUS, $result->status);
        self::assertSame(ExecutionLedgerState::LAUNCH_AMBIGUOUS, $result->record?->state);
        self::assertSame(ExecutionDispatchReservation::AMBIGUOUS, $result->record?->dispatchReservation?->launchPhase);
        self::assertNull($result->record?->launchObservation);
        self::assertFalse($ledger->claim($record->occurrence, $record->fence, 12, 30, $descriptor, $reservation->permissionId)->accepted);
        self::assertSame(ExecutionDispatchResult::REJECTED, $ledger->repairBeforeStartUnderLock($record->occurrence, $record->fence, $this->recovery(), 'source-1', static fn(): bool => false, 21_000_000)->status);
    }

    public function testIncompleteReceiptCannotClaimButExactReceiptCan(): void
    {
        [$ledger, $record, $reservation, $descriptor] = $this->prepared();
        $identity = new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id');
        $start = new ExecutionStartObservation(1700000000000000, 123456789);
        $ledger->launchUnderLock($record->occurrence, $record->fence, $this->recovery(), 'source-1', $descriptor, $reservation->permissionId, 11, static fn(): ExecutionLaunchObservation => new ExecutionLaunchObservation(123, null, 'started', 11, processIdentity: $identity, startObservation: $start), nowUs: 11_000_000);
        self::assertFalse($ledger->claim($record->occurrence, $record->fence, 12, 30, $descriptor, 'bad-permission')->accepted);
        self::assertTrue($ledger->claim($record->occurrence, $record->fence, 12, 30, $descriptor, $reservation->permissionId)->accepted);
    }

    public function testStaleFenceSourceAndDescriptorCannotLaunch(): void
    {
        [$ledger, $record, $reservation, $descriptor] = $this->prepared();
        $callback = static function (): ExecutionLaunchObservation {
            throw new LogicException('must not launch');
        };
        self::assertSame(ExecutionDispatchResult::STALE, $ledger->launchUnderLock($record->occurrence, new ExecutionFence(1, 'wrong'), $this->recovery(), 'source-1', $descriptor, $reservation->permissionId, 11, $callback, nowUs: 11_000_000)->status);
        self::assertSame(ExecutionDispatchResult::STALE, $ledger->launchUnderLock($record->occurrence, $record->fence, $this->recovery(), 'other-source', $descriptor, $reservation->permissionId, 11, $callback, nowUs: 11_000_000)->status);
        $other = new ExecutionLaunchDescriptor($record->correlation->executionId, 1, 'other-nonce', occurrence: $record->occurrence);
        self::assertSame(ExecutionDispatchResult::STALE, $ledger->launchUnderLock($record->occurrence, $record->fence, $this->recovery(), 'source-1', $other, $reservation->permissionId, 11, $callback, nowUs: 11_000_000)->status);
    }

    public function testRepairRequiresAbsenceAndIssuesNewPermissionPreservingDeadline(): void
    {
        [$ledger, $record, $reservation, $descriptor] = $this->prepared();
        $ledger->launchUnderLock($record->occurrence, $record->fence, $this->recovery(), 'source-1', $descriptor, $reservation->permissionId, 11, static function (): never {
            throw new RuntimeException('unknown');
        }, nowUs: 11_000_000);
        $repaired = $ledger->repairBeforeStartUnderLock($record->occurrence, $record->fence, $this->recovery(), 'source-1', static fn(): bool => true, 15_000_000);
        self::assertSame(ExecutionDispatchResult::SAME_GENERATION_UNCLAIMED, $repaired->status);
        self::assertNotSame($reservation->permissionId, $repaired->record?->dispatchReservation?->permissionId);
        self::assertSame(15, $repaired->record?->dispatchReservation?->reservedAt);
        self::assertSame(25, $repaired->record?->dispatchReservation?->deadline);
        self::assertSame(15, $repaired->record?->updatedAt);
    }

    public function testUnclaimedNeedsResolutionCannotRecordOutcome(): void
    {
        $ledger = $this->ledger();
        $record = $this->record()->transition(state: ExecutionLedgerState::NEEDS_RESOLUTION);
        $ledger->admit($record);

        self::assertSame(
            'rejected',
            $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 2)->status,
        );
        self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $ledger->observe($record->occurrence)?->state);
    }

    public function testRotationRejectsEstablishedRecoveryDeadlineAtBoundary(): void
    {
        $ledger = $this->ledger();
        $identity = new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id');
        $start = new ExecutionStartObservation(1700000000000000, 123456789);
        $record = $this->record()->transition(
            startAnchorIdentity: $identity,
            startObservation: $start,
            recoveryAge: 180.0,
            recoveryDeadline: 1700000180000000,
        );
        $ledger->admit($record);
        self::assertSame('accepted', $ledger->revoke($record->occurrence, $this->recovery(), 1700000180000000, $record->fence, 'source-1')->status);

        self::assertSame(
            'rejected',
            $ledger->allocateGeneration($record->occurrence, $this->recovery(), new ExecutionFence(2, 'new-fence'), 1700000180000000, $record->fence, 'source-1')->status,
        );
    }

    public function testTerminalOutcomeWinsAndRotationFencesOldAuthorityWhilePreservingAnchor(): void
    {
        [$ledger, $record, $reservation, $descriptor] = $this->prepared();
        $identity = new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id');
        $start = new ExecutionStartObservation(1700000000000000, 123456789);
        $ledger->launchUnderLock($record->occurrence, $record->fence, $this->recovery(), 'source-1', $descriptor, $reservation->permissionId, 11, static fn(): ExecutionLaunchObservation => new ExecutionLaunchObservation(123, null, 'started', 11, processIdentity: $identity, startObservation: $start), nowUs: 11_000_000);
        $ledger->claim($record->occurrence, $record->fence, 12, 30, $descriptor, $reservation->permissionId);
        self::assertSame('accepted', $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 13)->status);
        self::assertSame(ExecutionDispatchResult::REJECTED, $ledger->rotateAfterClosureUnderLock($record->occurrence, $this->recovery(), new ExecutionFence(2, 'new-fence'), 14, $record->fence, 'source-1')->status);
        self::assertSame(ExecutionLedgerState::SUCCEEDED, $ledger->observe($record->occurrence)?->state);
    }

    private function prepared(): array
    {
        $ledger = $this->ledger();
        $record = $this->record();
        $reservation = new ExecutionDispatchReservation('dispatcher', 10, 20);
        $descriptor = $this->descriptor($record);
        $ledger->admit($record);
        $ledger->reserveDispatch($record->occurrence, $record->fence, $reservation);
        $ledger->recordLaunchDescriptor($record->occurrence, $record->fence, $this->recovery(), $descriptor, 'source-1');
        return [$ledger, $record, $reservation, $descriptor];
    }

    private function ledger(): SQLiteExecutionLedger
    {
        return SQLiteLedgerFixture::ledger($this->directory);
    }

    private function recovery(): ExecutionRecoveryFence
    {
        return new ExecutionRecoveryFence('recovery-1', 1);
    }

    private function descriptor(ExecutionLedgerRecord $record): ExecutionLaunchDescriptor
    {
        return new ExecutionLaunchDescriptor($record->correlation->executionId, 1, 'nonce-1', occurrence: $record->occurrence);
    }

    private function record(): ExecutionLedgerRecord
    {
        $occurrence = new ExecutionOccurrenceKey('task', 'occurrence');
        return new ExecutionLedgerRecord($occurrence, new ExecutionIdentity('task', 'occurrence', 'attempt', 'run-1', 'execution-1'), 1, new ExecutionFence(1, 'fence-1'), ExecutionLedgerState::LAUNCH_REQUIRED, 'manifest', [
            'schema' => 'dev', 'source' => ['source-1', 'revision-1'], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [1.0, 2.0], 'recoveryMaxAge' => 180.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]],
        ], createdAt: 1, updatedAt: 1, recoveryFence: $this->recovery(), sourceIdentity: 'source-1', sourceRevision: 'revision-1');
    }
}
