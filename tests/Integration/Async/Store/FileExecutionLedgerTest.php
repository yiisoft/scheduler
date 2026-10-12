<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Store;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\{
    ExecutionDispatchReservation, ExecutionDispatchResult, ExecutionFence, ExecutionGenerationResult,
    ExecutionIdentity, ExecutionLaunchObservation, ExecutionLedgerQueryInterface, ExecutionLedgerRecord, ExecutionLedgerState,
    ExecutionOccurrenceKey, ExecutionOutcome, ExecutionProcessClosure, ExecutionRecoveryFence,
    ExecutionRecoveryResult, ExecutionRevocationResult, ExecutionTerminalResult,
};
use Yiisoft\Scheduler\Integration\Async\Store\{FileExecutionLedger, FileExecutionLedgerCodec, FileExecutionLedgerSnapshotWriter};
use PHPUnit\Framework\Attributes\DataProvider;

use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;

final class FileExecutionLedgerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/schedule-ledger-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testMaximumScalarDepthSurvivesAdmissionAndReopen(): void
    {
        $outcome = new ExecutionOutcome(ExecutionOutcome::SUCCEEDED, 'done', $this->deepTree(32));
        $record = new ExecutionLedgerRecord(
            new ExecutionOccurrenceKey('task', 'deep'),
            new ExecutionIdentity('task', 'deep', 'attempt', 'run-1', 'execution-1'),
            1,
            new ExecutionFence(1, 'fence-1'),
            ExecutionLedgerState::SUCCEEDED,
            'launch-intent',
            'manifest',
            $this->policy(metadata: $this->deepTree(20)),
            1,
            1,
            $outcome,
            $this->deepTree(32),
            null,
            new ExecutionLaunchObservation(123, null, 'complete', 1, ExecutionProcessClosure::confirmed(0, 'closed')),
        );

        $ledger = new FileExecutionLedger($this->directory);
        self::assertTrue($ledger->admit($record)->newlyAdmitted);
        self::assertEquals($record, (new FileExecutionLedger($this->directory))->observe($record->occurrence));
    }

    public function testScalarDepthOverflowFailsBeforeAdmission(): void
    {
        $this->expectException(InvalidArgumentException::class);
        try {
            $record = new ExecutionLedgerRecord(
                new ExecutionOccurrenceKey('task', 'too-deep'),
                new ExecutionIdentity('task', 'too-deep', 'attempt', 'run-1', 'execution-1'),
                1,
                new ExecutionFence(1, 'fence-1'),
                ExecutionLedgerState::LAUNCH_REQUIRED,
                'launch-intent',
                'manifest',
                $this->policy(metadata: $this->deepTree(33)),
            );
            (new FileExecutionLedger($this->directory))->admit($record);
        } finally {
            self::assertSame([], glob($this->directory . '/*') ?: []);
        }
    }

    public function testAdmissionPublishesActiveLookupAndTaskHistoryMarker(): void
    {
        $record = $this->record();
        $ledger = new FileExecutionLedger($this->directory);
        self::assertTrue($ledger->admit($record)->newlyAdmitted);

        self::assertInstanceOf(ExecutionLedgerQueryInterface::class, $ledger);
        self::assertEquals([$record], $ledger->activeExecutions());
        self::assertEquals([$record], $ledger->ownershipHoldingExecutions());
        self::assertTrue($ledger->hasTaskHistory('task'));
    }

    #[DataProvider('unfinishedStateProvider')]
    public function testReopenAfterAdmissionAndUnfinishedTransitionsPublishesTheSameRecord(string $state): void
    {
        $record = $this->record($state);
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($record);
        if ($state === ExecutionLedgerState::CLAIMED) {
            $ledger->claim($record->occurrence, $record->fence, 10, 30);
        }

        $reopened = new FileExecutionLedger($this->directory);
        self::assertEquals([$reopened->observe($record->occurrence)], $reopened->activeExecutions());
        self::assertSame($state, $reopened->activeExecutions()[0]->state);
    }

    public static function unfinishedStateProvider(): array
    {
        return [
            'admitted' => [ExecutionLedgerState::LAUNCH_REQUIRED],
            'claimed' => [ExecutionLedgerState::CLAIMED],
            'running' => [ExecutionLedgerState::RUNNING],
        ];
    }

    public function testTerminalRecordLeavesActiveLookupButRetainsMarkerBasedHistory(): void
    {
        $record = $this->record();
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($record);
        $ledger->claim($record->occurrence, $record->fence, 10, 30);
        $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 11);

        $reopened = new FileExecutionLedger($this->directory);
        self::assertSame([], $reopened->activeExecutions());
        $ownership = $reopened->ownershipHoldingExecutions();
        self::assertCount(1, $ownership);
        self::assertSame($record->occurrence->occurrenceId, $ownership[0]->occurrence->occurrenceId);
        self::assertSame(ExecutionLedgerState::SUCCEEDED, $ownership[0]->state);
        self::assertSame(ExecutionLedgerState::SUCCEEDED, $reopened->observe($record->occurrence)?->state);
        self::assertTrue($ledger->hasTaskHistory('task'));

        // History is a marker, not a scan of retained execution snapshots.
        foreach ($this->recordFiles() as $path) {
            unlink($path);
        }
        self::assertTrue((new FileExecutionLedger($this->directory))->hasTaskHistory('task'));
    }

    public function testRepairRebuildsMissingActiveIndexAndMissingTaskMarkerFromSnapshots(): void
    {
        $record = $this->record();
        (new FileExecutionLedger($this->directory))->admit($record);
        unlink($this->indexFile('active'));
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->repairIndexes();
        self::assertEquals([$record], $ledger->activeExecutions());

        unlink($this->indexFile('history'));
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->repairIndexes();
        self::assertEquals([$record], $ledger->activeExecutions());
        self::assertTrue($ledger->hasTaskHistory('task'));
    }

    public function testRepairDropsOrphanActiveReferenceWithoutFabricatingRecord(): void
    {
        $record = $this->record();
        (new FileExecutionLedger($this->directory))->admit($record);
        foreach ($this->recordFiles() as $path) {
            unlink($path);
        }
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->repairIndexes();

        self::assertSame([], $ledger->activeExecutions());
        self::assertNull($ledger->observe($record->occurrence));
    }

    public function testMalformedActiveIndexFailsClosedWithoutMintingRecords(): void
    {
        $record = $this->record();
        (new FileExecutionLedger($this->directory))->admit($record);
        $active = $this->indexFile('active');
        file_put_contents($active, '{malformed');

        $this->expectException(InvalidArgumentException::class);
        new FileExecutionLedger($this->directory);
    }

    public function testMalformedOwnershipIndexRejectsAdmissionBeforeAnyDurableMutation(): void
    {
        $existing = $this->record();
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($existing);
        $ownership = $this->indexFile('ownership');
        file_put_contents($ownership, '{"version":1,"entries":[]}');
        $newRecord = $this->record(occurrenceId: 'new');
        $before = [];
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            $before[basename($path)] = file_get_contents($path);
        }

        $this->expectException(InvalidArgumentException::class);
        try {
            $ledger->admit($newRecord);
        } finally {
            $after = [];
            foreach (glob($this->directory . '/*') ?: [] as $path) {
                $after[basename($path)] = file_get_contents($path);
            }
            self::assertSame($before, $after);
            self::assertFileDoesNotExist($this->recordPath($newRecord) . '.lock');
        }
    }

    #[DataProvider('postSnapshotAdmissionFailureProvider')]
    public function testStartupRepairRestoresIndexesAfterPostSnapshotAdmissionFailure(string $pathFragment): void
    {
        $record = $this->record();
        $writer = new PathFailingSnapshotWriter($pathFragment);
        $failed = false;
        try {
            (new FileExecutionLedger($this->directory, writer: $writer))->admit($record);
        } catch (InvalidArgumentException) {
            $failed = true;
        }
        self::assertTrue($failed);

        $reopened = new FileExecutionLedger($this->directory);
        self::assertTrue($reopened->hasTaskHistory('task'));
        self::assertEquals([$record], $reopened->activeExecutions());
        self::assertEquals($record, $reopened->observe($record->occurrence));
    }

    public static function postSnapshotAdmissionFailureProvider(): array
    {
        return [
            'history marker' => ['history-marker-'],
        ];
    }

    public function testMalformedActiveIndexFailsDuringLedgerConstruction(): void
    {
        $record = $this->record();
        (new FileExecutionLedger($this->directory))->admit($record);
        file_put_contents($this->indexFile('active'), '{malformed');

        $this->expectException(InvalidArgumentException::class);
        new FileExecutionLedger($this->directory);
    }

    public function testMalformedTaskHistoryMarkerFailsDuringLedgerConstruction(): void
    {
        $record = $this->record();
        (new FileExecutionLedger($this->directory))->admit($record);
        file_put_contents($this->indexFile('history'), '{malformed');

        $this->expectException(InvalidArgumentException::class);
        new FileExecutionLedger($this->directory);
    }

    public function testMalformedUnrelatedClosedSnapshotFailsLedgerConstruction(): void
    {
        $record = $this->record();
        $closed = $this->record(occurrenceId: 'closed');
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($record);
        $ledger->admit($closed);
        $ledger->claim($closed->occurrence, $closed->fence, 10, 30);
        $ledger->recordOutcome($closed->occurrence, $closed->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 11);
        file_put_contents($this->recordPath($closed), '{malformed');

        $this->expectException(InvalidArgumentException::class);
        new FileExecutionLedger($this->directory);
    }

    public function testPreIndexSnapshotsMigrateOnceThroughRepairAndAdmissionIsIdempotent(): void
    {
        $record = $this->record();
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($record);
        foreach ($this->indexFiles() as $path) {
            unlink($path);
        }

        $ledger->repairIndexes();
        $ledger->repairIndexes();
        self::assertEquals([$record], $ledger->activeExecutions());
        $duplicate = $ledger->admit($this->record(configurationFingerprint: 'changed'));
        self::assertFalse($duplicate->newlyAdmitted);
        self::assertEquals($record->correlation, $duplicate->record->correlation);
        self::assertCount(1, $ledger->activeExecutions());
    }

    public function testDispatchCasAmbiguityRejectsUnknownClosureRedispatch(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record();
        $ledger->admit($record);
        $reservation = new ExecutionDispatchReservation('dispatcher-a', 10, 20);
        self::assertSame(ExecutionDispatchResult::ACCEPTED, $ledger->reserveDispatch($record->occurrence, $record->fence, $reservation)->status);
        self::assertSame(ExecutionDispatchResult::ALREADY_LAUNCHED, $ledger->reserveDispatch($record->occurrence, $record->fence, $reservation)->status);
        $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionLaunchObservation(null, null, 'unknown', 11, ExecutionProcessClosure::unknown('unknown')));
        $retry = $ledger->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher-b', 12, 30), 12);
        self::assertSame(ExecutionDispatchResult::REJECTED, $retry->status);
        self::assertNull($retry->record);
    }

    public function testUnlimitedClaimSurvivesReopenAndArbitraryClockTimeButWrongFenceIsRejected(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record();
        $ledger->admit($record);
        $claim = $ledger->claim($record->occurrence, $record->fence, 10, null);
        self::assertTrue($claim->accepted);
        self::assertNull((new FileExecutionLedger($this->directory))->observe($record->occurrence)?->claimLeaseDeadline);

        self::assertSame(ExecutionTerminalResult::STALE_FENCED, $ledger->recordOutcome($record->occurrence, new ExecutionFence(1, 'wrong'), new ExecutionOutcome(ExecutionOutcome::FAILED), PHP_INT_MAX)->status);
        self::assertSame(ExecutionTerminalResult::ACCEPTED, (new FileExecutionLedger($this->directory))->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), PHP_INT_MAX)->status);
    }

    public function testExpiredClaimRemainsOwnedAndCannotBeRedispatchedOrTerminalized(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record();
        $ledger->admit($record);
        $ledger->claim($record->occurrence, $record->fence, 10, 20);

        self::assertSame(ExecutionRecoveryResult::LEASE_EXPIRED_OBSERVED, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 20)->status);
        $redispatch = $ledger->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher', 21, 30), 21);
        self::assertSame(ExecutionDispatchResult::REJECTED, $redispatch->status);
        self::assertNull($redispatch->record);
        self::assertSame(ExecutionRevocationResult::REJECTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 21)->status);
        self::assertSame(ExecutionGenerationResult::REQUIRES_REVOCATION, $ledger->allocateGeneration($record->occurrence, new ExecutionRecoveryFence('recovery-1'), new ExecutionFence(2, 'fence-2'), 21)->status);
        self::assertSame(ExecutionLedgerState::CLAIMED, (new FileExecutionLedger($this->directory))->observe($record->occurrence)?->state);
        self::assertSame(ExecutionTerminalResult::REJECTED, $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 20)->status);
    }

    public function testRecoveryUsesClaimLeaseBeforeDispatchReservation(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record();
        $ledger->admit($record);
        $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher', 10, 12));
        $ledger->claim($record->occurrence, $record->fence, 11, 30);
        self::assertSame(ExecutionRecoveryResult::REJECTED, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 20)->status);
        self::assertSame(ExecutionRecoveryResult::LEASE_EXPIRED_OBSERVED, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 30)->status);
    }

    public function testNeedsResolutionAndTerminalOrRevokedRecordsCannotRedispatch(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record(ExecutionLedgerState::NEEDS_RESOLUTION);
        $ledger->admit($record);
        $retry = $ledger->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher', 10, 11), 100);
        self::assertSame(ExecutionDispatchResult::REJECTED, $retry->status);
        self::assertNull($retry->record);
        self::assertEquals($record, $ledger->observe($record->occurrence));

        $terminal = $this->record(occurrenceId: 'terminal');
        $ledger->admit($terminal);
        $ledger->claim($terminal->occurrence, $terminal->fence, 10, 20);
        $ledger->recordOutcome($terminal->occurrence, $terminal->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 11);
        self::assertSame(ExecutionDispatchResult::STALE, $ledger->redispatchSameGeneration($terminal->occurrence, $terminal->fence, new ExecutionDispatchReservation('dispatcher', 12, 13), 100)->status);
    }

    public function testGenerationAllocationAfterClaimRequiresRevocation(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record();
        $ledger->admit($record);
        $ledger->claim($record->occurrence, $record->fence, 10, 30);
        self::assertSame(30, (new FileExecutionLedger($this->directory))->observe($record->occurrence)?->claimLeaseDeadline);

        self::assertSame(ExecutionRevocationResult::REJECTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 11)->status);
        $allocation = $ledger->allocateGeneration(
            $record->occurrence,
            new ExecutionRecoveryFence('recovery-1'),
            new ExecutionFence(2, 'fence-2'),
            12,
        );

        self::assertSame(ExecutionGenerationResult::REQUIRES_REVOCATION, $allocation->status);
        self::assertSame(ExecutionLedgerState::CLAIMED, $allocation->record?->state);
    }

    #[DataProvider('launchObservationRejectionProvider')]
    public function testLaunchObservationRejectionDoesNotMutateAnyDurableState(string $case): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record(occurrenceId: $case);
        $observation = new ExecutionLaunchObservation(null, null, 'unknown', 11, ExecutionProcessClosure::unknown('unknown'));

        if ($case === 'claimed') {
            $ledger->admit($record);
            $ledger->claim($record->occurrence, $record->fence, 10, 30);
        } elseif ($case === 'running') {
            $ledger->admit($this->record(ExecutionLedgerState::RUNNING, occurrenceId: $case, claimLeaseDeadline: 30));
        } elseif ($case === 'terminal') {
            $ledger->admit($record);
            $ledger->claim($record->occurrence, $record->fence, 10, 30);
            $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 11);
        } elseif ($case === 'revoked') {
            $ledger->admit($record);
            $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 10);
        } elseif ($case === 'closure-bearing') {
            $ledger->admit($record);
            $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher', 10, 20));
            $observation = new ExecutionLaunchObservation(123, null, 'complete', 11, ExecutionProcessClosure::confirmed(0, 'closed'));
        } else {
            $ledger->admit($this->record($case, occurrenceId: $case));
        }

        $before = $ledger->observe($record->occurrence);
        $observationFence = $case === 'terminal' ? new ExecutionFence(2, 'stale') : $before->fence;
        $result = $ledger->observeLaunch($record->occurrence, $observationFence, $observation);

        if ($case === 'terminal') {
            self::assertSame(ExecutionDispatchResult::ACCEPTED, $result->status);
            self::assertNotNull($result->record);
            self::assertSame($record->occurrence->occurrenceId, $result->record->occurrence->occurrenceId);
            self::assertSame(ExecutionLedgerState::SUCCEEDED, $result->record->state);
            self::assertEquals([$result->record], $ledger->ownershipHoldingExecutions());
        } else {
            self::assertSame(ExecutionDispatchResult::REJECTED, $result->status);
            self::assertNull($result->record);
        }
        $durable = (new FileExecutionLedger($this->directory))->observe($record->occurrence);
        self::assertNotNull($durable);
        self::assertSame($before->occurrence->occurrenceId, $durable->occurrence->occurrenceId);
        self::assertSame($before->state, $durable->state);
    }

    public function testConfirmedClosureFencedAfterTerminalReleasesOwnershipAndPreservesOutcome(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record();
        $ledger->admit($record);
        $ledger->claim($record->occurrence, $record->fence, 10, 30);
        $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 11);

        self::assertCount(1, $ledger->ownershipHoldingExecutions());
        $observed = $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionLaunchObservation(123, null, 'closed', 12, ExecutionProcessClosure::confirmed(0, 'closed')));

        self::assertSame(ExecutionDispatchResult::ACCEPTED, $observed->status);
        self::assertSame(ExecutionLedgerState::SUCCEEDED, $observed->record?->state);
        self::assertSame(ExecutionOutcome::SUCCEEDED, $observed->record?->outcome?->status);
        self::assertSame([], $ledger->ownershipHoldingExecutions());
        $reopened = new FileExecutionLedger($this->directory);
        self::assertSame(ExecutionOutcome::SUCCEEDED, $reopened->observe($record->occurrence)?->outcome?->status);
        self::assertTrue($reopened->observe($record->occurrence)?->launchObservation?->closure?->isConfirmed());
        self::assertSame([], $reopened->ownershipHoldingExecutions());
    }

    public function testStaleConfirmedClosureDoesNotReleaseTerminalOwnership(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record();
        $ledger->admit($record);
        $ledger->claim($record->occurrence, $record->fence, 10, 30);
        $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::FAILED), 11);

        $result = $ledger->observeLaunch($record->occurrence, new ExecutionFence(2, 'stale'), new ExecutionLaunchObservation(null, null, 'closed', 12, ExecutionProcessClosure::confirmed(1, 'closed')));

        self::assertSame(ExecutionDispatchResult::STALE, $result->status);
        self::assertCount(1, $ledger->ownershipHoldingExecutions());
        self::assertSame(ExecutionOutcome::FAILED, $ledger->observe($record->occurrence)?->outcome?->status);
        self::assertNull($ledger->observe($record->occurrence)?->launchObservation);
    }

    public function testTerminalOutcomeIsUnchangedByConfirmedClosure(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record();
        $ledger->admit($record);
        $ledger->claim($record->occurrence, $record->fence, 10, 30);
        $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::FAILED, 'worker-failed'), 11);

        $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionLaunchObservation(null, null, 'closed', 12, ExecutionProcessClosure::confirmed(7, 'closed')));

        $stored = $ledger->observe($record->occurrence);
        self::assertSame(ExecutionLedgerState::FAILED, $stored?->state);
        self::assertSame('worker-failed', $stored?->outcome?->reason);
        self::assertSame(7, $stored?->launchObservation?->closure?->exitCode);
    }

    public function testConfirmedClosureObservationIsAmbiguousAndEnablesRevocation(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record();
        $ledger->admit($record);
        $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher', 10, 20));

        $observed = $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionLaunchObservation(123, null, 'complete', 11, ExecutionProcessClosure::confirmed(0, 'closed')));

        self::assertSame(ExecutionDispatchResult::AMBIGUOUS, $observed->status);
        self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $observed->record?->state);
        self::assertSame(ExecutionRevocationResult::ACCEPTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 12)->status);
    }

    public static function launchObservationRejectionProvider(): array
    {
        return [
            'launch required gate' => [ExecutionLedgerState::LAUNCH_REQUIRED],
            'needs resolution gate' => [ExecutionLedgerState::NEEDS_RESOLUTION],
            'claimed' => ['claimed'],
            'running' => ['running'],
            'terminal' => ['terminal'],
            'revoked' => ['revoked'],

        ];
    }

    #[DataProvider('nativeWriterFaultProvider')]
    public function testWriterFaultSeamPreservesPreviousSnapshot(string $fault): void
    {
        // Native fwrite/fflush/fsync/fclose failures are OS/stream dependent and cannot be
        // injected through the production writer seam; this deterministic seam covers the
        // ledger's failure boundary for each native phase. Rename is covered below.
        $record = $this->record();
        (new FileExecutionLedger($this->directory))->admit($record);
        $writer = new FailingSnapshotWriter($fault);
        $ledger = new FileExecutionLedger($this->directory, writer: $writer);
        $this->expectException(InvalidArgumentException::class);
        try {
            $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher', 10, 20));
        } finally {
            self::assertEquals($record, (new FileExecutionLedger($this->directory))->observe($record->occurrence));
        }
    }

    public static function nativeWriterFaultProvider(): array
    {
        return [['partial write'], ['flush'], ['fsync'], ['close'], ['rename']];
    }

    public function testNativeWriterCleansTemporaryFileWhenRenameFails(): void
    {
        $record = $this->record();
        $path = $this->directory . '/record-' . hash('sha256', (string) $record->occurrence) . '.json';
        mkdir($path);
        try {
            $this->expectException(InvalidArgumentException::class);
            (new FileExecutionLedger($this->directory))->admit($record);
        } finally {
            self::assertSame([], glob($path . '.*.tmp') ?: []);
            rmdir($path);
        }
    }

    public function testFailedInjectedWritePreservesPreviousSnapshot(): void
    {
        $writer = new FailingSnapshotWriter();
        $ledger = new FileExecutionLedger($this->directory, writer: $writer);
        $record = $this->record();
        $ledger->admit($record);
        $writer->fail = true;
        $this->expectException(InvalidArgumentException::class);
        try {
            $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher', 10, 20));
        } finally {
            $writer->fail = false;
            self::assertEquals($record, (new FileExecutionLedger($this->directory))->observe($record->occurrence));
        }
    }

    public function testWrongFenceDoesNotMutateSnapshot(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record();
        $ledger->admit($record);
        self::assertSame(ExecutionDispatchResult::STALE, $ledger->reserveDispatch($record->occurrence, new ExecutionFence(1, 'wrong'), new ExecutionDispatchReservation('dispatcher', 10, 20))->status);
        self::assertEquals($record, $ledger->observe($record->occurrence));
    }

    public function testMalformedSnapshotIsNotAccepted(): void
    {
        $record = $this->record();
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($record);
        $path = glob($this->directory . '/record-*.json')[0];
        file_put_contents($path, '{malformed');
        $this->expectException(InvalidArgumentException::class);
        $ledger->observe($record->occurrence);
    }

    public function testOldLedgerVersionFourSnapshotIsRejectedBeforeLedgerUse(): void
    {
        $record = $this->record();
        (new FileExecutionLedger($this->directory))->admit($record);
        $value = json_decode((new FileExecutionLedgerCodec())->encode($record), true, 64, JSON_THROW_ON_ERROR);
        $value['version'] = 4;
        file_put_contents($this->recordPath($record), json_encode($value, JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        new FileExecutionLedger($this->directory);
    }

    public function testMalformedTaskPolicyIsRejectedBeforeLedgerUse(): void
    {
        $record = $this->record();
        (new FileExecutionLedger($this->directory))->admit($record);
        $value = json_decode((new FileExecutionLedgerCodec())->encode($record), true, 64, JSON_THROW_ON_ERROR);
        $value['taskSnapshot']['task']['id'] = ['task'];
        file_put_contents($this->recordPath($record), json_encode($value, JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        new FileExecutionLedger($this->directory);
    }

    public function testIdentityMismatchIsNotAccepted(): void
    {
        $record = $this->record();
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($record);
        $path = glob($this->directory . '/record-*.json')[0];
        file_put_contents($path, (new FileExecutionLedgerCodec())->encode($this->record(occurrenceId: 'other')));
        $this->expectException(InvalidArgumentException::class);
        $ledger->observe($record->occurrence);
    }

    /** @return list<string> */
    private function recordFiles(): array
    {
        return glob($this->directory . '/record-*.json') ?: [];
    }

    /** @return list<string> */
    private function indexFiles(): array
    {
        return array_values(array_filter(glob($this->directory . '/*') ?: [], static function (string $path): bool {
            $name = basename($path);
            return str_contains($name, 'active') || str_contains($name, 'history') || str_contains($name, 'index') || str_contains($name, 'marker');
        }));
    }

    private function indexFile(string $kind): string
    {
        $files = array_values(array_filter($this->indexFiles(), static fn(string $path): bool => str_contains(basename($path), $kind)));
        self::assertCount(1, $files);
        return $files[0];
    }

    private function recordPath(ExecutionLedgerRecord $record): string
    {
        return $this->directory . '/record-' . hash('sha256', (string) $record->occurrence) . '.json';
    }

    private function deepTree(int $depth): array
    {
        $value = 'leaf';
        for ($i = 0; $i < $depth; ++$i) {
            $value = ['value' => $value];
        }
        return $value;
    }

    private function record(string $state = ExecutionLedgerState::LAUNCH_REQUIRED, string $configurationFingerprint = 'manifest', string $occurrenceId = 'occurrence', ?int $claimLeaseDeadline = null): ExecutionLedgerRecord
    {
        $occurrence = new ExecutionOccurrenceKey('task', $occurrenceId);
        return new ExecutionLedgerRecord(
            $occurrence,
            new ExecutionIdentity('task', $occurrenceId, 'attempt', 'run-1', 'execution-1'),
            1,
            new ExecutionFence(1, 'fence-1'),
            $state,
            'launch-intent',
            $configurationFingerprint,
            taskSnapshot: $this->policy(),
            createdAt: 1,
            updatedAt: 1,
            recoveryFence: new ExecutionRecoveryFence('recovery-1'),
            claimLeaseDeadline: $claimLeaseDeadline,
        );
    }

    /** @return array{format:int,source:array{0:null,1:null},task:array<string,mixed>} */
    private function policy(?array $metadata = null): array
    {
        return [
            'format' => 4,
            'source' => [null, null],
            'task' => [
                'id' => 'task',
                'mode' => 'async',
                'timeout' => [1.0, 2.0],
                'metadata' => $metadata ?? [],
                'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]],
                'overlap' => 'allow',
                'missed' => ['skip', null],
            ],
        ];
    }
}

final class FailingSnapshotWriter implements FileExecutionLedgerSnapshotWriter
{
    public bool $fail = false;

    public function __construct(private readonly string $fault = 'write') {}

    public function write(string $path, string $data): void
    {
        if ($this->fail || $this->fault !== 'write') {
            throw new InvalidArgumentException('injected snapshot ' . $this->fault . ' failure');
        }
        file_put_contents($path, $data);
    }
}

final class PathFailingSnapshotWriter implements FileExecutionLedgerSnapshotWriter
{
    public function __construct(private readonly string $pathFragment) {}

    public function write(string $path, string $data): void
    {
        if (str_contains($path, $this->pathFragment)) {
            throw new InvalidArgumentException('injected path-specific snapshot failure');
        }
        file_put_contents($path, $data);
    }
}
