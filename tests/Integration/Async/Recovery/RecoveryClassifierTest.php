<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Recovery;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\{ExecutionDispatchReservation, ExecutionFence, ExecutionIdentity, ExecutionLedgerQueryInterface, ExecutionLedgerRecord, ExecutionLedgerState, ExecutionLaunchDescriptor, ExecutionProcessIdentity, ExecutionRecoveryFence, ExecutionStartObservation, ExecutionOutcome, ExecutionOccurrenceKey, ExecutionLedgerRecoveryPage};
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessHandleInterface, ProcessIdentity, ProcessLookupResult, ProcessScopeProof};
use Yiisoft\Scheduler\Integration\Async\Recovery\{RecoveryAdmissionValidatorInterface, RecoveryClassifier, RecoveryDeadline, RecoveryProcessObserverInterface};
use Yiisoft\Scheduler\Integration\Async\Process\ProcessResult;
use Yiisoft\Scheduler\Integration\Async\Recovery\RecoveryAction;

final class RecoveryClassifierTest extends TestCase
{
    public function testNoMatchBeforeStartIsSafeRelaunchAndDoesNotUseMissedPolicy(): void
    {
        $record = $this->record();
        $observer = new FakeObserver(new ProcessLookupResult(ProcessLookupResult::NO_MATCH));
        $action = $this->classifier([$record], $observer)->classify('source', 10_000_000, 1)[0];

        self::assertSame('safe-same-generation-relaunch', $action->kind);
        self::assertSame($record->launchDescriptor, $action->record->launchDescriptor);
        self::assertSame($record->fence, $action->expectedFence);
        self::assertSame($record->recoveryFence, $action->recoveryFence);
        self::assertNull($action->deadlineUs);
    }

    public function testDescriptorFreeAsyncPreStartIsRelaunchableWithoutProcessLookup(): void
    {
        $record = $this->record(state: ExecutionLedgerState::LAUNCH_REQUIRED)->transition(
            launchDescriptor: null,
            processIdentity: null,
            startAnchorIdentity: null,
            startObservation: null,
            attemptStartObservation: null,
            recoveryAge: null,
            recoveryDeadline: null,
        );
        $observer = new FakeObserver(new ProcessLookupResult(ProcessLookupResult::BLOCKED));
        $action = $this->classifier([$record], $observer)->classify('source', 1_000_000, 1)[0];

        self::assertSame(RecoveryAction::RELAUNCH, $action->kind);
        self::assertNull($action->lookup);
        self::assertSame(0, $observer->lookups);
    }

    public function testDescriptorFreeSynchronousExecutionIsBlockedAndNeverRelaunched(): void
    {
        $record = $this->record(state: ExecutionLedgerState::LAUNCH_REQUIRED, mode: 'sync')->transition(
            launchDescriptor: null,
            processIdentity: null,
            startAnchorIdentity: null,
            startObservation: null,
            attemptStartObservation: null,
            recoveryAge: null,
            recoveryDeadline: null,
        );
        $action = $this->classifier([$record], new FakeObserver(new ProcessLookupResult(ProcessLookupResult::BLOCKED)))->classify('source', 1_000_000, 1)[0];

        self::assertSame(RecoveryAction::BLOCKED, $action->kind);
    }

    public function testStartedNoMatchIsBlockedBeforeDeadlineWithoutRelaunch(): void
    {
        $record = $this->record(new ExecutionStartObservation(1_000_000, 1), 2.0);
        $observer = new FakeObserver(new ProcessLookupResult(ProcessLookupResult::NO_MATCH));

        $action = $this->classifier([$record], $observer)->classify('source', 2_500_000, 1)[0];

        self::assertSame('blocked/unresolved', $action->kind);
        self::assertSame(3_000_000, $action->deadlineUs);
    }

    /** @dataProvider closedRepairHandoffs */
    public function testClosedReceiptIncompleteRepairHandoffIsSafeRelaunch(string $state, string $phase): void
    {
        $record = $this->record(state: $state, reservationPhase: $phase);
        $identity = new ProcessIdentity(12, 12, 12, '3', 'boot', $record->launchDescriptor);
        $observer = new FakeObserver(new ProcessLookupResult(
            ProcessLookupResult::CLOSED,
            proof: ProcessScopeProof::closedEsrchFromLookup($record->launchDescriptor, $identity),
        ));

        $action = $this->classifier([$record], $observer)->classify('source', 10_000_000, 1)[0];

        self::assertSame('safe-same-generation-relaunch', $action->kind);
    }

    public static function closedRepairHandoffs(): array
    {
        return [
            'starting request' => [ExecutionLedgerState::LAUNCH_REQUESTED, ExecutionDispatchReservation::STARTING],
            'ambiguous request' => [ExecutionLedgerState::LAUNCH_AMBIGUOUS, ExecutionDispatchReservation::AMBIGUOUS],
        ];
    }

    public function testClosedPreparedReservationCannotRelaunch(): void
    {
        $record = $this->record(state: ExecutionLedgerState::LAUNCH_REQUESTED, reservationPhase: ExecutionDispatchReservation::PREPARED);
        $identity = new ProcessIdentity(12, 12, 12, '3', 'boot', $record->launchDescriptor);
        $observer = new FakeObserver(new ProcessLookupResult(
            ProcessLookupResult::CLOSED,
            proof: ProcessScopeProof::closedEsrchFromLookup($record->launchDescriptor, $identity),
        ));

        $action = $this->classifier([$record], $observer)->classify('source', 10_000_000, 1)[0];

        self::assertSame('blocked/unresolved', $action->kind);
    }

    public function testClosedClaimedStartedRecordCannotRelaunch(): void
    {
        $record = $this->record(new ExecutionStartObservation(1_000_000, 1), 2.0);
        $identity = new ProcessIdentity(1, 1, 1, '1', 'boot', $record->launchDescriptor);
        $observer = new FakeObserver(new ProcessLookupResult(
            ProcessLookupResult::CLOSED,
            proof: ProcessScopeProof::closedEsrchFromLookup($record->launchDescriptor, $identity),
        ));

        $action = $this->classifier([$record], $observer)->classify('source', 2_500_000, 1)[0];

        self::assertSame(RecoveryAction::AGGREGATE_REDELIVERY, $action->kind);
        self::assertSame(3_000_000, $action->deadlineUs);
    }

    public function testStartedNoMatchExpiresAtDeadlineWithoutNewLaunch(): void
    {
        $record = $this->record(new ExecutionStartObservation(1_000_000, 1), 2.0);
        $observer = new FakeObserver(new ProcessLookupResult(ProcessLookupResult::NO_MATCH));

        $action = $this->classifier([$record], $observer)->classify('source', 3_000_000, 1)[0];

        self::assertSame('expired/unresolved', $action->kind);
        self::assertSame(3_000_000, $action->deadlineUs);
    }

    public function testAdmissionMismatchStillObservesRootAndBlocksRelaunch(): void
    {
        $observer = new FakeObserver(new ProcessLookupResult(ProcessLookupResult::NO_MATCH));
        $action = $this->classifier([$this->record()], $observer, false)->classify('source', 10_000_000, 1)[0];

        self::assertSame('blocked/unresolved', $action->kind);
        self::assertSame(1, $observer->lookups);
        self::assertNotNull($action->lookup);
    }

    public function testLiveMatchIsWaitObservationWithHandleAndExpiredMatchRemainsObservable(): void
    {
        $record = $this->record(new ExecutionStartObservation(1_000_000, 1), 2.0);
        $handle = new FakeHandle();
        $identity = new ProcessIdentity(1, 1, 1, '1', 'boot', $record->launchDescriptor);
        $observer = new FakeObserver(new ProcessLookupResult(
            ProcessLookupResult::MATCH,
            $identity,
            $handle,
            ProcessScopeProof::liveFromLookup($record->launchDescriptor, $identity),
        ));

        $live = $this->classifier([$record], $observer)->classify('source', 2_999_999, 1)[0];
        self::assertSame('unresolved/wait-observe', $live->kind);
        self::assertSame($handle, $live->attachedHandle);
        self::assertSame('live worker observed; retain supervision', $live->reason);

        $expired = $this->classifier([$record], $observer)->classify('source', 3_000_000, 1)[0];
        self::assertSame('expired/observe-live', $expired->kind);
        self::assertSame($handle, $expired->attachedHandle);
    }

    public function testMatchWithoutLiveProofIsBlockedAndNotRetained(): void
    {
        $record = $this->record(new ExecutionStartObservation(1_000_000, 1), 2.0);
        $identity = new ProcessIdentity(1, 1, 1, '1', 'boot', $record->launchDescriptor);
        $action = $this->classifier([$record], new FakeObserver(new ProcessLookupResult(
            ProcessLookupResult::MATCH,
            $identity,
            new FakeHandle(),
        )))->classify('source', 1_000_000, 1)[0];

        self::assertSame('blocked/unresolved', $action->kind);
        self::assertSame('process identity lookup is uncertain', $action->reason);
    }

    /** @dataProvider uncertainLookupStatuses */
    public function testUncertainLookupIsBlocked(string $status): void
    {
        $observer = new FakeObserver(new ProcessLookupResult($status));
        $action = $this->classifier([$this->record()], $observer)->classify('source', 1_000_000, 1)[0];

        self::assertSame('blocked/unresolved', $action->kind);
    }

    public static function uncertainLookupStatuses(): array
    {
        return [[ProcessLookupResult::BLOCKED], [ProcessLookupResult::AMBIGUOUS], [ProcessLookupResult::UNREADABLE]];
    }

    public function testDeadlineUsesHardTimeoutWithMicrosecondCeiling(): void
    {
        $record = $this->record(new ExecutionStartObservation(1_000_000, 1), 1.0000001, timeout: [1.0, 1.0000002]);
        $deadline = RecoveryDeadline::forRecord($record);

        self::assertSame(2_000_001, $deadline);
    }

    public function testHandoffRejectsStaleOrMissingRecoveryCapability(): void
    {
        $record = $this->record();
        $this->expectException(InvalidArgumentException::class);
        new RecoveryAction(
            'safe-same-generation-relaunch',
            $record,
            $record->fence,
            null,
        );
    }

    public function testWaitRequiresMatchingLookupAndAttachedHandle(): void
    {
        $record = $this->record();
        $identity = new ProcessIdentity(12, 12, 12, '3', 'boot', $record->launchDescriptor);
        $closed = new ProcessLookupResult(
            ProcessLookupResult::CLOSED,
            proof: ProcessScopeProof::closedEsrchFromLookup($record->launchDescriptor, $identity),
        );

        foreach ([null, new ProcessLookupResult(ProcessLookupResult::NO_MATCH), $closed] as $lookup) {
            $this->assertWaitRejected($record, $lookup, new FakeHandle());
        }

        $match = new ProcessLookupResult(ProcessLookupResult::MATCH, $identity, new FakeHandle());
        $this->assertWaitRejected($record, $match, null);
    }

    public function testLegacyWaitAliasRequiresLiveLookupProof(): void
    {
        $record = $this->record();
        $identity = new ProcessIdentity(12, 12, 12, '3', 'boot', $record->launchDescriptor);

        $this->expectException(InvalidArgumentException::class);
        new RecoveryAction(
            RecoveryAction::WAIT,
            $record,
            $record->fence,
            $record->recoveryFence,
            new ProcessLookupResult(ProcessLookupResult::MATCH, $identity, new FakeHandle()),
            attachedHandle: new FakeHandle(),
        );
    }

    public function testUnboundedCompatibilityClassificationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->classifier([], new FakeObserver(new ProcessLookupResult(ProcessLookupResult::NO_MATCH)))->classify('source', 1_000_000);
    }

    public function testClassifyContinuesThroughRecoveryPages(): void
    {
        $first = $this->record();
        $second = $this->record(occurrenceId: 'second');
        $observer = new FakeObserver(new ProcessLookupResult(ProcessLookupResult::NO_MATCH));

        $actions = $this->classifier([$first, $second], $observer, pages: [[$first], [$second]])->classify('source', 1_000_000, 2);

        self::assertCount(2, $actions);
    }

    public function testEmptyRecoveryPageDoesNotFallBackToLegacyQueries(): void
    {
        $record = $this->record();
        $observer = new FakeObserver(new ProcessLookupResult(ProcessLookupResult::NO_MATCH));

        self::assertSame([], $this->classifier([$record], $observer, pages: [])->classify('source', 1_000_000, 1));
        self::assertSame(0, $observer->lookups);
    }

    public function testRepeatedRecoveryCursorStopsCompatibilityScan(): void
    {
        $record = $this->record();
        $observer = new FakeObserver(new ProcessLookupResult(ProcessLookupResult::NO_MATCH));

        $actions = $this->classifier([$record], $observer, pages: [[$record], ['repeat']])->classify('source', 1_000_000, 2);

        self::assertCount(1, $actions);
    }

    public function testProofForAnotherDescriptorCannotDriveRecovery(): void
    {
        $record = $this->record();
        $otherDescriptor = new ExecutionLaunchDescriptor('execution', 1, 'other', occurrence: $record->occurrence);
        $identity = new ProcessIdentity(12, 12, 12, '3', 'boot', $otherDescriptor);
        $observer = new FakeObserver(new ProcessLookupResult(
            ProcessLookupResult::CLOSED,
            proof: ProcessScopeProof::closedEsrchFromLookup($otherDescriptor, $identity),
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->classifier([$record], $observer)->classify('source', 1_000_000, 1);
    }

    public function testTerminalRecordsAreObservedWithoutAdmission(): void
    {
        $terminal = $this->record(null, null, ExecutionLedgerState::SUCCEEDED, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED));
        $observer = new FakeObserver(new ProcessLookupResult(ProcessLookupResult::NO_MATCH));
        $actions = $this->classifier([$terminal], $observer)->classify('source', 1_000_000, 1);

        self::assertSame(['terminal/no-op'], array_map(static fn($a) => $a->kind, $actions));
        self::assertSame(1, $observer->lookups);
    }

    private function classifier(array $records, FakeObserver $observer, bool $admitted = true, ?array $pages = null): RecoveryClassifier
    {
        return new RecoveryClassifier(new class ($records, $pages) implements ExecutionLedgerQueryInterface {
            public function __construct(private array $records, private ?array $pages = null) {}

            public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
            {
                foreach ($this->records as $record) {
                    if ($record->occurrence == $occurrence) {
                        return $record;
                    }
                }
                return null;
            }

            public function hasTaskHistory(string $taskKey): bool
            {
                foreach ($this->records as $record) {
                    if ($record->occurrence->taskKey === $taskKey) {
                        return true;
                    }
                }
                return false;
            }

            public function activeExecutions(string $sourceIdentity): array
            {
                return [];
            }

            public function ownershipHoldingExecutions(string $sourceIdentity): array
            {
                return [];
            }

            public function recoveryPage(string $sourceIdentity, ?string $cursor, int $limit): ExecutionLedgerRecoveryPage
            {
                if ($this->pages === null) {
                    return new ExecutionLedgerRecoveryPage($cursor === null ? $this->records : [], null);
                }
                $index = $cursor === null ? 0 : (int) $cursor;
                if (($this->pages[$index] ?? null) === ['repeat']) {
                    return new ExecutionLedgerRecoveryPage([], (string) $index);
                }
                return new ExecutionLedgerRecoveryPage($this->pages[$index] ?? [], isset($this->pages[$index + 1]) ? (string) ($index + 1) : null);
            }

            public function repairIndexes(): void {}
        }, $observer, new class ($admitted) implements RecoveryAdmissionValidatorInterface {
            public function __construct(private bool $admitted) {}

            public function accepts(ExecutionLedgerRecord $record, string $sourceIdentity): bool
            {
                return $this->admitted;
            }
        });
    }

    private function assertWaitRejected(ExecutionLedgerRecord $record, ?ProcessLookupResult $lookup, ?ProcessHandleInterface $handle): void
    {
        try {
            new RecoveryAction(
                RecoveryAction::WAIT,
                $record,
                $record->fence,
                $record->recoveryFence,
                $lookup,
                attachedHandle: $handle,
            );
            self::fail('Invalid WAIT recovery action was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    private function record(?ExecutionStartObservation $start = null, ?float $age = 10.0, string $state = ExecutionLedgerState::RUNNING, ?ExecutionOutcome $outcome = null, array $timeout = [1.0, 2.0], ?string $reservationPhase = null, string $occurrenceId = 'occurrence', string $mode = 'async'): ExecutionLedgerRecord
    {
        $fence = new ExecutionFence(1, 'fence');
        $occurrence = new ExecutionOccurrenceKey('task', $occurrenceId);
        $descriptor = new ExecutionLaunchDescriptor('execution', 1, 'nonce', occurrence: $occurrence);
        $recoveryAge = $mode === 'sync' || $start === null ? null : ($age ?? 1.0);
        $recoveryDeadline = $recoveryAge === null || $start === null ? null : $start->wallMicroseconds + RecoveryDeadline::durationMicroseconds(max($recoveryAge, $timeout[1]));
        $processIdentity = $start === null ? null : new ExecutionProcessIdentity(1, 1, 1, '1', 'boot');

        return new ExecutionLedgerRecord(
            occurrence: $occurrence,
            correlation: new ExecutionIdentity('task', $occurrenceId, 'attempt', 'run', 'execution'),
            attempt: 1,
            fence: $fence,
            state: $state,
            configurationFingerprint: 'fingerprint',
            taskSnapshot: ['schema' => 'dev', 'source' => ['source', 'revision'], 'task' => [
                'id' => 'task', 'mode' => $mode, 'timeout' => $mode === 'sync' ? null : $timeout, 'recoveryMaxAge' => $mode === 'sync' ? null : ($age ?? 1.0),
                'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]],
                'overlap' => 'allow', 'missed' => ['skip', null],
            ]],
            createdAt: 0,
            updatedAt: 0,
            outcome: $outcome,
            dispatchReservation: $reservationPhase === null ? null : new ExecutionDispatchReservation('test-dispatcher', 0, 10_000_000, '0123456789abcdef0123456789abcdef', $reservationPhase),
            recoveryFence: new ExecutionRecoveryFence('recovery', 1),
            sourceIdentity: 'source',
            sourceRevision: 'revision',
            launchDescriptor: $descriptor,
            processIdentity: $processIdentity,
            startAnchorIdentity: $processIdentity,
            startObservation: $start,
            attemptStartObservation: $start,
            recoveryAge: $recoveryAge,
            recoveryDeadline: $recoveryDeadline,
        );
    }
}

final class FakeObserver implements RecoveryProcessObserverInterface
{
    public int $lookups = 0;

    public function __construct(private ProcessLookupResult $result) {}

    public function lookup(ExecutionLaunchDescriptor $descriptor, ?ExecutionProcessIdentity $expected = null): ProcessLookupResult
    {
        $this->lookups++;
        return $this->result;
    }
}

final class FakeHandle implements ProcessHandleInterface
{
    public function scopeProof(): ProcessScopeProof
    {
        return ProcessScopeProof::closedTerminalFromRetainedPoll(new ExecutionLaunchDescriptor('test', 1, 'test'), new ProcessIdentity(10, 10, 10, '1', 'boot', new ExecutionLaunchDescriptor('test', 1, 'test')));
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

    public function terminateSoft(): void {}

    public function terminateHard(): void {}
}
