<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Store;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\{
    ExecutionDispatchReservation, ExecutionDispatchResult, ExecutionFence, ExecutionGenerationResult,
    ExecutionIdentity, ExecutionLaunchDescriptor, ExecutionLaunchObservation, ExecutionLedgerQueryInterface, ExecutionLedgerRecord, ExecutionLedgerState,
    ExecutionOccurrenceKey, ExecutionOutcome, ExecutionProcessClosure, ExecutionProcessIdentity, ExecutionRecoveryFence, ExecutionStartObservation,
    ExecutionRecoveryResult, ExecutionRevocationResult, ExecutionTerminalResult,
};
use Yiisoft\Scheduler\Integration\Async\Store\{FileExecutionLedger, FileExecutionLedgerCodec, FileExecutionLedgerSnapshotWriter};
use PHPUnit\Framework\Attributes\DataProvider;

use function getmypid;
use function is_dir;
use function is_file;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;

final class FileExecutionLedgerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        static $sequence = 0;
        do {
            $this->directory = sys_get_temp_dir() . '/schedule-ledger-' . getmypid() . '-' . $sequence++;
        } while (is_dir($this->directory));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
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
            'manifest',
            $this->policy(metadata: $this->deepTree(20)),
            1,
            1,
            $outcome,
            $this->deepTree(32),
            null,
            new ExecutionLaunchObservation(null, null, 'complete', 1, ExecutionProcessClosure::confirmed(0, 'closed')),
            sourceIdentity: 'source-1',
            sourceRevision: 'revision-1',
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
                'manifest',
                $this->policy(metadata: $this->deepTree(33)),
            );
            (new FileExecutionLedger($this->directory))->admit($record);
        } finally {
            self::assertSame([], glob($this->directory . '/*') ?: []);
        }
    }

    public function testRecoveryPageRejectsLimitsOutsideOperationalBound(): void
    {
        $ledger = new FileExecutionLedger($this->directory);

        foreach ([0, -1, ExecutionLedgerQueryInterface::MAX_RECOVERY_PAGE_SIZE + 1] as $limit) {
            try {
                $ledger->recoveryPage('source-1', null, $limit);
                self::fail('Expected an invalid recovery page limit.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRecoveryPageIsSortedDeduplicatedSourceScopedAndCursorContinues(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $first = $this->record(occurrenceId: 'a', taskKey: 'a');
        $second = $this->record(occurrenceId: 'b', taskKey: 'b');
        $other = $this->record(occurrenceId: 'c', taskKey: 'aa', sourceIdentity: 'other');
        $ledger->admit($second);
        $ledger->admit($other);
        $ledger->admit($first);

        $page = $ledger->recoveryPage('source-1', null, 1);
        self::assertEquals([$first], $page->records);
        self::assertNotNull($page->nextCursor);
        self::assertStringStartsNotWith('v3.', $page->nextCursor);
        $continued = $ledger->recoveryPage('source-1', $page->nextCursor, 1);
        self::assertSame([], $continued->records);
        self::assertNotNull($continued->nextCursor);
        $last = $ledger->recoveryPage('source-1', $continued->nextCursor, 1);
        self::assertEquals([$second], $last->records);
        self::assertNull($last->nextCursor);
        self::expectException(InvalidArgumentException::class);
        $ledger->recoveryPage('source-1', 'not-a-cursor', 1);
    }

    public function testRecoveryPageRejectsCompleteCursorWithNonEndPosition(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($this->record(occurrenceId: 'a', taskKey: 'a'));
        $ledger->admit($this->record(occurrenceId: 'b', taskKey: 'b'));

        $page = $ledger->recoveryPage('source-1', null, 1);
        self::assertNotNull($page->nextCursor);
        $cursor = $this->mutateRecoveryCursor($page->nextCursor, 0, ['position' => 0, 'complete' => true]);

        $this->expectException(InvalidArgumentException::class);
        $ledger->recoveryPage('source-1', $cursor, 1);
    }

    public function testRecoveryPageRejectsNullPredecessorAwayFromFirstBoundary(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($this->record(occurrenceId: 'a', taskKey: 'a'));
        $ledger->admit($this->record(occurrenceId: 'b', taskKey: 'b'));

        $page = $ledger->recoveryPage('source-1', null, 1);
        self::assertNotNull($page->nextCursor);
        $cursor = $this->mutateRecoveryCursor($page->nextCursor, 0, ['previous' => null]);

        $this->expectException(InvalidArgumentException::class);
        $ledger->recoveryPage('source-1', $cursor, 1);
    }

    public function testRecoveryPageRejectsAssociativeCursorStreams(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $payload = [
            'source' => hash('sha256', 'source-1'),
            'identity' => [[1, 2, 3, 4], [5, 6, 7, 8]],
            'streams' => [
                'active' => ['position' => 0, 'complete' => false, 'previous' => null],
                'ownership' => ['position' => 0, 'complete' => false, 'previous' => null],
            ],
        ];
        $cursor = 'v3.' . rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $this->expectException(InvalidArgumentException::class);
        $ledger->recoveryPage('source-1', $cursor, 1);
    }

    public function testRecoveryPageRejectsDuplicateCursorObjectMembers(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $json = '{"source":"' . hash('sha256', 'source-1') . '","source":"' . hash('sha256', 'source-1') . '","identity":[[1,2,3,4],[5,6,7,8]],"streams":[{"position":0,"complete":false,"previous":null},{"position":0,"complete":false,"previous":null}]}';
        $cursor = 'v3.' . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        $this->expectException(InvalidArgumentException::class);
        $ledger->recoveryPage('source-1', $cursor, 1);
    }

    public function testRecoveryPageRejectsNumericKeyObjectsWhereCursorArraysAreRequired(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $json = '{"source":"' . hash('sha256', 'source-1') . '","identity":[[1,2,3,4],[5,6,7,8]],"streams":{"0":{"position":0,"complete":false,"previous":null},"1":{"position":0,"complete":false,"previous":null}}}';
        $cursor = 'v3.' . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        $this->expectException(InvalidArgumentException::class);
        $ledger->recoveryPage('source-1', $cursor, 1);
    }

    public function testRecoveryCursorAdvancesEmptyOwnershipStreamBeforeContinuation(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        foreach (['a', 'b'] as $occurrenceId) {
            $record = $this->record(occurrenceId: $occurrenceId)->transition(
                state: ExecutionLedgerState::RUNNING,
                launchObservation: new ExecutionLaunchObservation(
                    null,
                    null,
                    'closed',
                    1,
                    ExecutionProcessClosure::confirmed(0, 'closed'),
                ),
            );
            $ledger->admit($record);
        }

        self::assertSame([], $ledger->ownershipHoldingExecutions('source-1'));
        $first = $ledger->recoveryPage('source-1', null, 1);
        self::assertSame('a', $first->records[0]->occurrence->occurrenceId);
        self::assertNotNull($first->nextCursor);
        $second = $ledger->recoveryPage('source-1', $first->nextCursor, 1);
        self::assertSame('b', $second->records[0]->occurrence->occurrenceId);
        self::assertNull($second->nextCursor);
    }

    public function testFreshLedgerQueriesAreEmptyAndAdmissionCanProceed(): void
    {
        $ledger = new FileExecutionLedger($this->directory);

        self::assertSame([], $ledger->activeExecutions('source-1'));
        self::assertSame([], $ledger->ownershipHoldingExecutions('source-1'));

        self::assertTrue($ledger->admit($this->record())->newlyAdmitted);
    }

    public function testAdmissionPublishesActiveLookupAndTaskHistoryMarker(): void
    {
        $record = $this->record();
        $ledger = new FileExecutionLedger($this->directory);
        self::assertTrue($ledger->admit($record)->newlyAdmitted);

        self::assertInstanceOf(ExecutionLedgerQueryInterface::class, $ledger);
        self::assertEquals([$record], $ledger->activeExecutions('source-1'));
        self::assertEquals([$record], $ledger->ownershipHoldingExecutions('source-1'));
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
        self::assertEquals([$reopened->observe($record->occurrence)], $reopened->activeExecutions('source-1'));
        self::assertSame($state, $reopened->activeExecutions('source-1')[0]->state);
    }

    public static function unfinishedStateProvider(): array
    {
        return [
            'admitted' => [ExecutionLedgerState::LAUNCH_REQUIRED],
            'claimed' => [ExecutionLedgerState::CLAIMED],
            'running' => [ExecutionLedgerState::RUNNING],
        ];
    }

    public function testDescriptorFreeSynchronousTerminalReleasesOwnershipAndRetainsHistoryMarker(): void
    {
        $record = $this->record();
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($record);
        $ledger->claim($record->occurrence, $record->fence, 10, 30);
        $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 11);

        $reopened = new FileExecutionLedger($this->directory);
        self::assertSame([], $reopened->activeExecutions('source-1'));
        self::assertSame([], $reopened->ownershipHoldingExecutions('source-1'));
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
        self::assertEquals([$record], $ledger->activeExecutions('source-1'));

        unlink($this->indexFile('history'));
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->repairIndexes();
        self::assertEquals([$record], $ledger->activeExecutions('source-1'));
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

        self::assertSame([], $ledger->activeExecutions('source-1'));
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

    public function testMissingOwnershipIndexRejectsAdmissionAndRetainsCanonicalRecord(): void
    {
        $existing = $this->record();
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($existing);
        $activeBefore = file_get_contents($this->indexFile('active'));
        $manifestBefore = file_get_contents($this->indexFile('history'));
        unlink($this->indexFile('ownership'));
        $newRecord = $this->record(occurrenceId: 'new');

        $this->expectException(InvalidArgumentException::class);
        try {
            $ledger->admit($newRecord);
        } finally {
            self::assertEquals($existing, $ledger->observe($existing->occurrence));
            self::assertSame($activeBefore, file_get_contents($this->indexFile('active')));
            self::assertSame($manifestBefore, file_get_contents($this->indexFile('history')));
            self::assertFileDoesNotExist($this->recordPath($newRecord));
        }
    }

    public function testMissingActiveIndexRejectsAdmissionAndRetainsCanonicalRecord(): void
    {
        $existing = $this->record();
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($existing);
        $ownershipBefore = file_get_contents($this->indexFile('ownership'));
        $manifestBefore = file_get_contents($this->indexFile('history'));
        unlink($this->indexFile('active'));
        $newRecord = $this->record(occurrenceId: 'new');

        $this->expectException(InvalidArgumentException::class);
        try {
            $ledger->admit($newRecord);
        } finally {
            self::assertEquals($existing, $ledger->observe($existing->occurrence));
            self::assertSame($ownershipBefore, file_get_contents($this->indexFile('ownership')));
            self::assertSame($manifestBefore, file_get_contents($this->indexFile('history')));
            self::assertFileDoesNotExist($this->recordPath($newRecord));
        }
    }

    public function testStartupRepairsMissingOwnershipIndexWithoutFreeingCanonicalOwnership(): void
    {
        $existing = $this->record();
        (new FileExecutionLedger($this->directory))->admit($existing);
        unlink($this->indexFile('ownership'));

        // Reopen through the constructor's real startup repair path. A missing
        // derived index must not turn an owned canonical record into capacity.
        $reopened = new FileExecutionLedger($this->directory);

        self::assertEquals($existing, $reopened->observe($existing->occurrence));
        self::assertEquals([$existing], $reopened->ownershipHoldingExecutions('source-1'));
        self::assertFileExists($this->indexFile('ownership'));
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
        self::assertEquals([$record], $reopened->activeExecutions('source-1'));
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

    public function testPreIndexSnapshotsAreRebuiltOnceThroughRepairAndAdmissionIsIdempotent(): void
    {
        $record = $this->record();
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($record);
        foreach ($this->indexFiles() as $path) {
            unlink($path);
        }

        $ledger->repairIndexes();
        $ledger->repairIndexes();
        self::assertEquals([$record], $ledger->activeExecutions('source-1'));
        $duplicate = $ledger->admit($this->record(configurationFingerprint: 'changed'));
        self::assertFalse($duplicate->newlyAdmitted);
        self::assertEquals($record->correlation, $duplicate->record->correlation);
        self::assertCount(1, $ledger->activeExecutions('source-1'));
    }

    public function testDispatchCasAmbiguityRejectsUnknownClosureRedispatch(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record();
        $ledger->admit($record);
        $reservation = new ExecutionDispatchReservation('dispatcher-a', 10, 20);
        self::assertSame(ExecutionDispatchResult::ACCEPTED, $ledger->reserveDispatch($record->occurrence, $record->fence, $reservation)->status);
        self::assertSame(ExecutionDispatchResult::ALREADY_LAUNCHED, $ledger->reserveDispatch($record->occurrence, $record->fence, $reservation)->status);
        $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionLaunchObservation(null, null, 'unknown', 11, ExecutionProcessClosure::unknown('unknown')), 'source-1');
        $retry = $ledger->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionDispatchReservation('dispatcher-b', 12, 30), 12_000_000, 'source-1');
        self::assertSame(ExecutionDispatchResult::REJECTED, $retry->status);
        self::assertNull($retry->record);
    }

    public function testAsyncClaimWithoutWorkerReceiptIsRejected(): void
    {
        $record = $this->record(mode: 'async');
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($record);

        $claim = $ledger->claim($record->occurrence, $record->fence, 10, 30);

        self::assertFalse($claim->accepted);
        self::assertEquals($record, $ledger->observe($record->occurrence));
    }

    public function testConsumedObservationRemainsNonClaimable(): void
    {
        $record = $this->record(started: true, mode: 'async');
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($record);
        $reservation = new ExecutionDispatchReservation('dispatcher', 10, 20);
        $ledger->reserveDispatch($record->occurrence, $record->fence, $reservation);
        $descriptor = new ExecutionLaunchDescriptor($record->correlation->executionId, $record->fence->generation, 'nonce-1', occurrence: $record->occurrence);
        $ledger->recordLaunchDescriptor($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), $descriptor, 'source-1');
        $identity = new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id');
        $start = new ExecutionStartObservation(1700000000000000, 123456789);

        $launch = $ledger->launchUnderLock(
            $record->occurrence,
            $record->fence,
            new ExecutionRecoveryFence('recovery-1', 1),
            'source-1',
            $descriptor,
            $reservation->permissionId,
            11,
            static function (): never {
                throw new InvalidArgumentException('launch became ambiguous');
            },
            nowUs: 11_000_000,
        );
        self::assertSame(ExecutionDispatchResult::AMBIGUOUS, $launch->status);

        $observed = $ledger->observeLaunch(
            $record->occurrence,
            $record->fence,
            new ExecutionRecoveryFence('recovery-1', 1),
            new ExecutionLaunchObservation(123, null, 'started', 12, ExecutionProcessClosure::unknown('running'), processIdentity: $identity, startObservation: $start),
            'source-1',
        );

        self::assertSame(ExecutionDispatchResult::AMBIGUOUS, $observed->status);
        self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $ledger->observe($record->occurrence)?->state);
        $claim = $ledger->claim($record->occurrence, $record->fence, 12, 30, $descriptor, $reservation->permissionId);

        self::assertFalse($claim->accepted);
        self::assertSame(ExecutionDispatchReservation::AMBIGUOUS, $ledger->observe($record->occurrence)?->dispatchReservation?->launchPhase);
    }

    public function testReservationAtRecoveryDeadlineIsRejectedWithoutMutation(): void
    {
        $record = $this->record(started: true, mode: 'async');
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($record);

        $result = $ledger->reserveDispatch(
            $record->occurrence,
            $record->fence,
            new ExecutionDispatchReservation('dispatcher', $record->recoveryDeadline, $record->recoveryDeadline + 10),
        );

        self::assertSame(ExecutionDispatchResult::REJECTED, $result->status);
        self::assertEquals($record, $ledger->observe($record->occurrence));
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

        self::assertSame(ExecutionRecoveryResult::LEASE_EXPIRED_OBSERVED, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 20_000_000, $record->fence, 'source-1')->status);
        $redispatch = $ledger->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionDispatchReservation('dispatcher', 21, 30), 21_000_000, 'source-1');
        self::assertSame(ExecutionDispatchResult::REJECTED, $redispatch->status);
        self::assertNull($redispatch->record);
        self::assertSame(ExecutionRevocationResult::REJECTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 21_000_000, $record->fence, 'source-1')->status);
        self::assertSame(ExecutionGenerationResult::REQUIRES_REVOCATION, $ledger->allocateGeneration($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionFence(2, 'fence-2'), 21_000_000, $record->fence, 'source-1')->status);
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
        self::assertSame(ExecutionRecoveryResult::REJECTED, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 20_000_000, $record->fence, 'source-1')->status);
        self::assertSame(ExecutionRecoveryResult::LEASE_EXPIRED_OBSERVED, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 30_000_000, $record->fence, 'source-1')->status);
    }

    public function testRecoveryDeadlineAcceptsPreDeadlineAndRejectsAtDeadline(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record(started: true, mode: 'async');
        $ledger->admit($record);

        self::assertSame(
            ExecutionRecoveryResult::SAME_GENERATION_UNCLAIMED,
            $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 1_700_000_179_999_999, $record->fence, 'source-1')->status,
        );
        self::assertSame(
            ExecutionRecoveryResult::RECOVERY_EXPIRED,
            $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 1_700_000_180_000_000, $record->fence, 'source-1')->status,
        );
    }

    public function testSameGenerationRedispatchRetainsDurableLaunchDescriptor(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record(started: true, mode: 'async');
        $ledger->admit($record);
        $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher-a', 10, 20));
        $descriptor = new ExecutionLaunchDescriptor('execution-1', 1, 'durable-nonce', occurrence: $record->occurrence);
        $ledger->recordLaunchDescriptor($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), $descriptor, 'source-1');

        $retry = $ledger->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionDispatchReservation('dispatcher-b', 21, 30), 20_000_000, 'source-1');

        self::assertSame(ExecutionDispatchResult::SAME_GENERATION_UNCLAIMED, $retry->status);
        self::assertSame($descriptor->marker(), $retry->record?->launchDescriptor?->marker());
    }

    public function testNeedsResolutionAndTerminalOrRevokedRecordsCannotRedispatch(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record(ExecutionLedgerState::NEEDS_RESOLUTION);
        $ledger->admit($record);
        $retry = $ledger->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionDispatchReservation('dispatcher', 10, 11), 100_000_000, 'source-1');
        self::assertSame(ExecutionDispatchResult::REJECTED, $retry->status);
        self::assertNull($retry->record);
        self::assertEquals($record, $ledger->observe($record->occurrence));

        $terminal = $this->record(occurrenceId: 'terminal');
        $ledger->admit($terminal);
        $ledger->claim($terminal->occurrence, $terminal->fence, 10, 20);
        $ledger->recordOutcome($terminal->occurrence, $terminal->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 11);
        self::assertSame(ExecutionDispatchResult::STALE, $ledger->redispatchSameGeneration($terminal->occurrence, $terminal->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionDispatchReservation('dispatcher', 12, 13), 100_000_000, 'source-1')->status);
    }

    public function testGenerationAllocationAfterClaimRequiresRevocation(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record();
        $ledger->admit($record);
        $ledger->claim($record->occurrence, $record->fence, 10, 30);
        self::assertSame(30, (new FileExecutionLedger($this->directory))->observe($record->occurrence)?->claimLeaseDeadline);

        self::assertSame(ExecutionRevocationResult::REJECTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 11_000_000, $record->fence, 'source-1')->status);
        $allocation = $ledger->allocateGeneration(
            $record->occurrence,
            new ExecutionRecoveryFence('recovery-1', 1),
            new ExecutionFence(2, 'fence-2'),
            12,
            $record->fence,
            'source-1',
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
            $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 10_000_000, $record->fence, 'source-1');
        } elseif ($case === 'closure-bearing') {
            $ledger->admit($record);
            $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher', 10, 20));
            $observation = new ExecutionLaunchObservation(null, null, 'complete', 11, ExecutionProcessClosure::confirmed(0, 'closed'));
        } else {
            $ledger->admit($this->record($case, occurrenceId: $case));
        }

        $before = $ledger->observe($record->occurrence);
        $observationFence = $before->fence;
        $result = $ledger->observeLaunch($record->occurrence, $observationFence, new ExecutionRecoveryFence('recovery-1', 1), $observation, 'source-1');

        if ($case === 'terminal') {
            self::assertSame(ExecutionDispatchResult::ACCEPTED, $result->status);
            self::assertNotNull($result->record);
            self::assertSame($record->occurrence->occurrenceId, $result->record->occurrence->occurrenceId);
            self::assertSame(ExecutionLedgerState::SUCCEEDED, $result->record->state);
            self::assertSame([], $ledger->ownershipHoldingExecutions('source-1'));
        } else {
            self::assertSame(ExecutionDispatchResult::REJECTED, $result->status);
            self::assertNull($result->record);
        }
        $durable = (new FileExecutionLedger($this->directory))->observe($record->occurrence);
        self::assertNotNull($durable);
        self::assertSame($before->occurrence->occurrenceId, $durable->occurrence->occurrenceId);
        self::assertSame($before->state, $durable->state);
    }

    public function testTerminalConfirmedObservationRetainsProcessIdentityAndStartFacts(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record(mode: 'sync');
        $ledger->admit($record);
        $reservation = new ExecutionDispatchReservation('dispatcher', 10, 20);
        $ledger->reserveDispatch($record->occurrence, $record->fence, $reservation);
        $descriptor = new ExecutionLaunchDescriptor($record->correlation->executionId, $record->fence->generation, 'nonce-1', occurrence: $record->occurrence);
        $ledger->recordLaunchDescriptor($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), $descriptor, 'source-1');
        $ledger->claim($record->occurrence, $record->fence, 10, 30);
        $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 11);

        $identity = new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id');
        $start = new ExecutionStartObservation(1700000000000000, 123456789);
        $result = $ledger->observeLaunch(
            $record->occurrence,
            $record->fence,
            new ExecutionRecoveryFence('recovery-1', 1),
            new ExecutionLaunchObservation(123, null, 'closed', 12, ExecutionProcessClosure::confirmed(0, 'closed'), processIdentity: $identity, startObservation: $start),
            'source-1',
        );

        self::assertSame(ExecutionDispatchResult::ACCEPTED, $result->status);
        $stored = (new FileExecutionLedger($this->directory))->observe($record->occurrence);
        self::assertEquals($identity, $stored?->processIdentity);
        self::assertEquals($start, $stored?->attemptStartObservation);
        self::assertNull($stored?->startAnchorIdentity);
        self::assertNull($stored?->startObservation);
        self::assertNull($stored?->recoveryAge);
        self::assertNull($stored?->recoveryDeadline);
        self::assertSame(ExecutionOutcome::SUCCEEDED, $stored?->outcome?->status);
    }

    public function testTerminalClosureWithoutReplacementFactsRetainsPersistedFacts(): void
    {
        $identity = new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id');
        $start = new ExecutionStartObservation(1700000000000000, 123456789);
        $occurrence = new ExecutionOccurrenceKey('task', 'terminal-facts');
        $record = new ExecutionLedgerRecord(
            $occurrence,
            new ExecutionIdentity('task', 'terminal-facts', 'attempt', 'run-1', 'execution-1'),
            1,
            new ExecutionFence(1, 'fence-1'),
            ExecutionLedgerState::SUCCEEDED,
            'manifest',
            taskSnapshot: $this->policy(),
            createdAt: 1,
            updatedAt: 1,
            outcome: new ExecutionOutcome(ExecutionOutcome::SUCCEEDED),
            launchObservation: new ExecutionLaunchObservation(123, null, 'closed', 11, ExecutionProcessClosure::unknown('pending'), processIdentity: $identity, startObservation: $start),
            recoveryFence: new ExecutionRecoveryFence('recovery-1', 1),
            sourceIdentity: 'source-1',
            sourceRevision: 'revision-1',
            launchDescriptor: new ExecutionLaunchDescriptor('execution-1', 1, 'nonce-1', occurrence: $occurrence),
            processIdentity: $identity,
            startAnchorIdentity: $identity,
            startObservation: $start,
            recoveryAge: 180.0,
            recoveryDeadline: 1700000180000000,
        );
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($record);

        $result = $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionLaunchObservation(null, null, 'closed', 12, ExecutionProcessClosure::confirmed(0, 'closed')), 'source-1');

        self::assertSame(ExecutionDispatchResult::ACCEPTED, $result->status);
        self::assertEquals($identity, $ledger->observe($record->occurrence)?->processIdentity);
        self::assertEquals($start, $ledger->observe($record->occurrence)?->startObservation);
    }

    public function testAggregateRedeliveryAtomicallyClosesAndAllocatesFreshGeneration(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $base = $this->record(started: true, mode: 'async');
        $identity = new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id');
        $start = new ExecutionStartObservation(1700000000000000, 123456789);
        $descriptor = new ExecutionLaunchDescriptor('execution-1', 1, 'nonce-1', occurrence: $base->occurrence);
        $reservation = new ExecutionDispatchReservation('dispatcher', 10, 20, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ExecutionDispatchReservation::OBSERVED);
        $record = $base->transition(
            state: ExecutionLedgerState::RUNNING,
            dispatchReservation: $reservation,
            launchDescriptor: $descriptor,
            launchObservation: new ExecutionLaunchObservation(123, null, 'started', 11, ExecutionProcessClosure::unknown('running'), processIdentity: $identity, startObservation: $start),
            processIdentity: $identity,
            startAnchorIdentity: $identity,
            startObservation: $start,
            attemptStartObservation: $start,
        );
        $ledger->admit($record);

        $result = $ledger->redeliverAfterClosureUnderLock(
            $record->occurrence,
            new ExecutionRecoveryFence('recovery-1', 1),
            new ExecutionFence(2, 'fence-2'),
            12_000_000,
            $record->fence,
            'source-1',
            new ExecutionLaunchObservation(123, null, 'closed', 12, ExecutionProcessClosure::confirmed(0, 'closed'), processIdentity: $identity, startObservation: $start),
            null,
        );

        self::assertSame(ExecutionGenerationResult::ALLOCATED, $result->status);
        self::assertSame(2, $result->record?->fence->generation);
        self::assertSame(ExecutionLedgerState::LAUNCH_REQUIRED, $result->record?->state);
        self::assertNull($result->record?->launchDescriptor);
        self::assertNull($result->record?->processIdentity);
        self::assertSame($record->recoveryDeadline, $result->record?->recoveryDeadline);

        $reopened = new FileExecutionLedger($this->directory);
        $persisted = $reopened->observe($record->occurrence);
        self::assertNotNull($persisted);
        self::assertCount(1, $persisted->retiredGenerations);
        $retired = $persisted->retiredGenerations[0]->attempt;
        self::assertSame(1, $retired->fence->generation);
        self::assertSame('generation-rotation', $persisted->retiredGenerations[0]->reason);
        self::assertEquals($reservation, $retired->dispatchReservation);
        self::assertTrue($retired->launchObservation?->closure?->isConfirmed());
        self::assertSame(12, $retired->launchObservation?->observedAt);
        self::assertEquals($identity, $retired->processIdentity);
        self::assertEquals($identity, $retired->launchObservation?->processIdentity);
        self::assertSame($record->recoveryDeadline, $retired->recoveryDeadline);
    }

    public function testGenerationRotationRetainsProcessTimingFactsAndClearsLaunchFacts(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record(started: true, mode: 'async');
        $ledger->admit($record);
        $reservation = new ExecutionDispatchReservation('dispatcher', 10, 20);
        $ledger->reserveDispatch($record->occurrence, $record->fence, $reservation);
        $descriptor = new ExecutionLaunchDescriptor('execution-1', 1, 'nonce-1', occurrence: $record->occurrence);
        $identity = new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id');
        $start = new ExecutionStartObservation(1700000000000000, 123456789);
        $recoveryFence = new ExecutionRecoveryFence('recovery-1', 1);
        $ledger->recordLaunchDescriptor($record->occurrence, $record->fence, $recoveryFence, $descriptor, 'source-1');
        $ledger->launchUnderLock(
            $record->occurrence,
            $record->fence,
            $recoveryFence,
            'source-1',
            $descriptor,
            $reservation->permissionId,
            11,
            static fn(): ExecutionLaunchObservation => new ExecutionLaunchObservation(123, null, 'closed', 11, ExecutionProcessClosure::confirmed(0, 'closed'), processIdentity: $identity, startObservation: $start),
            nowUs: 11_000_000,
        );
        $ledger->revoke($record->occurrence, $recoveryFence, 12_000_000, $record->fence, 'source-1');

        $allocation = $ledger->allocateGeneration($record->occurrence, $recoveryFence, new ExecutionFence(2, 'fence-2'), 13_000_000, $record->fence, 'source-1');
        $rotated = $allocation->record;

        self::assertSame(ExecutionGenerationResult::ALLOCATED, $allocation->status);
        self::assertNotNull($rotated);
        self::assertNull($rotated->launchDescriptor);
        self::assertEquals($record->startAnchorIdentity, $rotated->startAnchorIdentity);
        self::assertNull($rotated->processIdentity);
        self::assertNull($rotated->attemptStartObservation);
        self::assertEquals($start, $rotated->startObservation);
        self::assertSame($record->recoveryAge, $rotated->recoveryAge);
        self::assertIsInt($rotated->recoveryDeadline);
        self::assertSame($record->recoveryDeadline, $rotated->recoveryDeadline);
        self::assertNull($rotated->launchObservation);
        self::assertCount(1, $rotated->retiredGenerations);
        self::assertSame(1, $rotated->retiredGenerations[0]->attempt->fence->generation);
        self::assertSame($record->recoveryDeadline, $rotated->retiredGenerations[0]->attempt->recoveryDeadline);
        // A rotated generation must publish a complete receipt without moving the
        // immutable recovery anchor, and that receipt must remain claimable.
        $newReservation = new ExecutionDispatchReservation('dispatcher-2', 14, 20);
        $ledger->reserveDispatch($record->occurrence, new ExecutionFence(2, 'fence-2'), $newReservation);
        $newDescriptor = new ExecutionLaunchDescriptor('execution-1', 2, 'nonce-2', occurrence: $record->occurrence);
        $newRecoveryFence = $rotated->recoveryFence;
        self::assertNotNull($newRecoveryFence);
        $ledger->recordLaunchDescriptor($record->occurrence, new ExecutionFence(2, 'fence-2'), $newRecoveryFence, $newDescriptor, 'source-1');
        $newIdentity = new ExecutionProcessIdentity(456, 456, 456, 'new-start-ticks', 'boot-id');
        $newStart = new ExecutionStartObservation(1700000014000000, 987654321);
        $launch = $ledger->launchUnderLock(
            $record->occurrence,
            new ExecutionFence(2, 'fence-2'),
            $newRecoveryFence,
            'source-1',
            $newDescriptor,
            $newReservation->permissionId,
            15,
            static fn(): ExecutionLaunchObservation => new ExecutionLaunchObservation(456, null, 'started', 15, ExecutionProcessClosure::unknown('running'), processIdentity: $newIdentity, startObservation: $newStart),
            nowUs: 15_000_000,
        );
        self::assertSame(ExecutionDispatchResult::ACCEPTED, $launch->status);
        self::assertEquals($record->startAnchorIdentity, $launch->record?->startAnchorIdentity);
        self::assertEquals($record->startObservation, $launch->record?->startObservation);
        self::assertSame($record->recoveryAge, $launch->record?->recoveryAge);
        self::assertSame($record->recoveryDeadline, $launch->record?->recoveryDeadline);
        self::assertEquals($newIdentity, $launch->record?->processIdentity);
        self::assertEquals($newStart, $launch->record?->attemptStartObservation);
        self::assertTrue($ledger->claim($record->occurrence, new ExecutionFence(2, 'fence-2'), 16, 30, $newDescriptor, $newReservation->permissionId)->accepted);

        $reopened = new FileExecutionLedger($this->directory);
        $persisted = $reopened->observe($record->occurrence);
        self::assertNotNull($persisted);
        self::assertCount(1, $persisted->retiredGenerations);
        self::assertSame(1, $persisted->retiredGenerations[0]->attempt->fence->generation);
        self::assertSame('generation-rotation', $persisted->retiredGenerations[0]->reason);
        self::assertSame($record->recoveryAge, $persisted->retiredGenerations[0]->attempt->recoveryAge);
        self::assertIsFloat($persisted->retiredGenerations[0]->attempt->taskSnapshot['task']['recoveryMaxAge']);
        self::assertSame(180.0, $persisted->retiredGenerations[0]->attempt->taskSnapshot['task']['recoveryMaxAge']);
        self::assertSame([1.0, 2.0], $persisted->retiredGenerations[0]->attempt->taskSnapshot['task']['timeout']);
        self::assertEquals($record->startObservation, $persisted->startObservation);

        $stale = $ledger->observeLaunch(
            $record->occurrence,
            $record->fence,
            $recoveryFence,
            new ExecutionLaunchObservation(123, null, 'closed', 14, ExecutionProcessClosure::confirmed(0, 'closed'), processIdentity: $identity, startObservation: $start),
            'source-1',
        );
        self::assertSame(ExecutionDispatchResult::STALE, $stale->status);
    }

    public function testConfirmedClosureFencedAfterTerminalReleasesOwnershipAndPreservesOutcome(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record();
        $ledger->admit($record);
        $ledger->claim($record->occurrence, $record->fence, 10, 30);
        $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 11);

        self::assertSame([], $ledger->ownershipHoldingExecutions('source-1'));
        $observed = $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionLaunchObservation(null, null, 'closed', 12, ExecutionProcessClosure::confirmed(0, 'closed')), 'source-1');

        self::assertSame(ExecutionDispatchResult::ACCEPTED, $observed->status);
        self::assertSame(ExecutionLedgerState::SUCCEEDED, $observed->record?->state);
        self::assertSame(ExecutionOutcome::SUCCEEDED, $observed->record?->outcome?->status);
        self::assertSame([], $ledger->ownershipHoldingExecutions('source-1'));
        $reopened = new FileExecutionLedger($this->directory);
        self::assertSame(ExecutionOutcome::SUCCEEDED, $reopened->observe($record->occurrence)?->outcome?->status);
        self::assertTrue($reopened->observe($record->occurrence)?->launchObservation?->closure?->isConfirmed());
        self::assertSame([], $reopened->ownershipHoldingExecutions('source-1'));
    }

    public function testStaleFenceCannotBeAcceptedByTerminalNonConfirmedObservation(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $occurrence = new ExecutionOccurrenceKey('task', 'terminal-fence');
        $record = new ExecutionLedgerRecord(
            $occurrence,
            new ExecutionIdentity('task', 'terminal-fence', 'attempt', 'run-1', 'execution-1'),
            1,
            new ExecutionFence(1, 'fence-1'),
            ExecutionLedgerState::LAUNCH_REQUIRED,
            'manifest',
            taskSnapshot: $this->policy(),
            createdAt: 1,
            updatedAt: 1,
            recoveryFence: new ExecutionRecoveryFence('recovery-1', 1),
            sourceIdentity: 'source-1',
            sourceRevision: 'revision-1',
        );
        $ledger->admit($record);
        $ledger->claim($occurrence, $record->fence, 10, 30);
        $ledger->recordOutcome($occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 11);

        $result = $ledger->observeLaunch(
            $occurrence,
            new ExecutionFence(1, 'old-fence'),
            new ExecutionRecoveryFence('recovery-1', 1),
            new ExecutionLaunchObservation(null, null, 'late', 12),
            'planner-source',
        );

        self::assertSame(ExecutionDispatchResult::STALE, $result->status);
        self::assertNull($result->record);
        self::assertNull($ledger->observe($occurrence)?->launchObservation);
    }

    public function testStaleConfirmedClosureDoesNotReleaseTerminalOwnership(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record();
        $ledger->admit($record);
        $ledger->claim($record->occurrence, $record->fence, 10, 30);
        $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::FAILED), 11);

        $result = $ledger->observeLaunch($record->occurrence, new ExecutionFence(2, 'stale'), new ExecutionRecoveryFence('recovery-1', 1), new ExecutionLaunchObservation(null, null, 'closed', 12, ExecutionProcessClosure::confirmed(1, 'closed')), 'source-1');

        self::assertSame(ExecutionDispatchResult::STALE, $result->status);
        self::assertSame([], $ledger->ownershipHoldingExecutions('source-1'));
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

        $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionLaunchObservation(null, null, 'closed', 12, ExecutionProcessClosure::confirmed(7, 'closed')), 'source-1');

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

        $observed = $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionLaunchObservation(null, null, 'complete', 11, ExecutionProcessClosure::confirmed(0, 'closed')), 'source-1');

        self::assertSame(ExecutionDispatchResult::AMBIGUOUS, $observed->status);
        self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $observed->record?->state);
        self::assertSame(ExecutionDispatchReservation::AMBIGUOUS, $observed->record?->dispatchReservation?->launchPhase);
        self::assertFalse($ledger->claim($record->occurrence, $record->fence, 12, null)->accepted);
        self::assertSame(ExecutionRevocationResult::ACCEPTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 12_000_000, $record->fence, 'source-1')->status);
    }

    public function testIdenticalLaunchObservationIsIdempotentAndConflictingIdentityIsRejected(): void
    {
        $ledger = new FileExecutionLedger($this->directory);
        $record = $this->record(started: true, mode: 'async');
        $ledger->admit($record);
        $reservation = new ExecutionDispatchReservation('dispatcher', 10, 20);
        $ledger->reserveDispatch($record->occurrence, $record->fence, $reservation);
        $ledger->recordLaunchDescriptor(
            $record->occurrence,
            $record->fence,
            new ExecutionRecoveryFence('recovery-1', 1),
            new ExecutionLaunchDescriptor('execution-1', 1, 'nonce-1', occurrence: $record->occurrence),
            'source-1',
        );
        $observation = new ExecutionLaunchObservation(123, null, 'closed', 11, ExecutionProcessClosure::confirmed(0, 'closed'), processIdentity: new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id'), startObservation: new ExecutionStartObservation(1700000000, 123456789));

        $ledger->launchUnderLock(
            $record->occurrence,
            $record->fence,
            new ExecutionRecoveryFence('recovery-1', 1),
            'source-1',
            new ExecutionLaunchDescriptor('execution-1', 1, 'nonce-1', occurrence: $record->occurrence),
            $reservation->permissionId,
            11,
            static fn(): ExecutionLaunchObservation => $observation,
            nowUs: 11_000_000,
        );
        self::assertSame(ExecutionDispatchResult::ACCEPTED, $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), $observation, 'source-1')->status);
        $first = $ledger->observe($record->occurrence);
        self::assertNotNull($first);
        self::assertSame(ExecutionDispatchResult::ACCEPTED, $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), $observation, 'source-1')->status);
        self::assertEquals($first, $ledger->observe($record->occurrence));

        $identity = new ExecutionProcessIdentity(124, 124, 124, 'different-start', 'boot-id');
        $descriptor = new ExecutionLaunchDescriptor($record->correlation->executionId, 1, 'nonce-1', occurrence: $record->occurrence);
        $started = new ExecutionLaunchObservation(124, null, 'started', 12, ExecutionProcessClosure::unknown('running'), processIdentity: $identity, startObservation: new ExecutionStartObservation(1700000001, 2));
        self::assertSame(ExecutionDispatchResult::REJECTED, $ledger->recordLaunchDescriptor($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), $descriptor, 'source-1')->status);
        self::assertSame(ExecutionDispatchResult::REJECTED, $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), $started, 'source-1')->status);
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

    public function testIncompatibleSchemaIsRejectedWithoutSnapshotMutation(): void
    {
        $record = $this->record();
        (new FileExecutionLedger($this->directory))->admit($record);
        $path = $this->recordPath($record);
        $value = json_decode((new FileExecutionLedgerCodec())->encode($record), true, 64, JSON_THROW_ON_ERROR);
        $value['schema'] = 'legacy';
        $snapshot = json_encode($value, JSON_THROW_ON_ERROR);
        file_put_contents($path, $snapshot);

        $this->expectException(InvalidArgumentException::class);
        try {
            new FileExecutionLedger($this->directory);
        } finally {
            self::assertSame($snapshot, file_get_contents($path));
        }
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

    private function record(string $state = ExecutionLedgerState::LAUNCH_REQUIRED, string $configurationFingerprint = 'manifest', string $occurrenceId = 'occurrence', ?int $claimLeaseDeadline = null, bool $started = false, string $mode = 'sync', string $sourceIdentity = 'source-1', string $taskKey = 'task'): ExecutionLedgerRecord
    {
        $occurrence = new ExecutionOccurrenceKey($taskKey, $occurrenceId);
        $startAnchorIdentity = $started ? new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id') : null;
        $startObservation = $started ? new ExecutionStartObservation(1700000000000000, 123456789) : null;
        return new ExecutionLedgerRecord(
            $occurrence,
            new ExecutionIdentity($taskKey, $occurrenceId, 'attempt', 'run-1', 'execution-1'),
            1,
            new ExecutionFence(1, 'fence-1'),
            $state,
            $configurationFingerprint,
            taskSnapshot: $this->policy(mode: $mode, sourceIdentity: $sourceIdentity),
            createdAt: 1,
            updatedAt: 1,
            recoveryFence: new ExecutionRecoveryFence('recovery-1', 1),
            claimLeaseDeadline: $claimLeaseDeadline,
            recoveryAge: $started ? 180.0 : null,
            sourceIdentity: $sourceIdentity,
            sourceRevision: 'revision-1',
            processIdentity: null,
            startAnchorIdentity: $startAnchorIdentity,
            startObservation: $startObservation,
            recoveryDeadline: $started ? 1700000180000000 : null,
        );
    }

    /** @param array<string,mixed> $changes */
    private function mutateRecoveryCursor(string $cursor, int $stream, array $changes): string
    {
        $encoded = $cursor;
        $padding = (4 - strlen($encoded) % 4) % 4;
        $json = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);
        self::assertNotFalse($json);
        /** @var array{streams:array<int,array<string,mixed>>} $payload */
        $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        $payload['streams'][$stream] = array_merge($payload['streams'][$stream], $changes);
        return rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{schema:string,source:array{0:string,1:string},task:array<string,mixed>} */
    private function policy(?array $metadata = null, string $mode = 'async', string $sourceIdentity = 'source-1'): array
    {
        return [
            'schema' => 'dev',
            'source' => [$sourceIdentity, 'revision-1'],
            'task' => [
                'id' => 'task',
                'mode' => $mode,
                'timeout' => $mode === 'async' ? [1.0, 2.0] : null, 'recoveryMaxAge' => $mode === 'async' ? 180.0 : null,
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
