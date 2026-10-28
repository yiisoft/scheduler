<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\{
    ExecutionAdmission, ExecutionClaimResult, ExecutionCommandScope, ExecutionDispatchReservation, ExecutionDispatchResult,
    ExecutionFence, ExecutionGenerationResult, ExecutionIdentity, ExecutionLaunchDescriptor, ExecutionLaunchObservation,
    ExecutionLedgerCommandInterface, ExecutionLedgerQueryInterface, ExecutionLedgerRecord, ExecutionLedgerRecoveryPage, ExecutionLedgerState, ExecutionOccurrenceKey,
    ExecutionOutcome, ExecutionProcessClosure, ExecutionProcessIdentity, ExecutionRecoveryFence, ExecutionRecoveryResult, ExecutionStartObservation,
    ExecutionRevocationResult, ExecutionTerminalResult,
};

use function in_array;

use const PHP_INT_MAX;

final class ExecutionLedgerContractTest extends TestCase
{
    public function testPersistedRootDescriptorRejectsOtherScope(): void
    {
        $record = ledgerRecord();
        $descriptor = new ExecutionLaunchDescriptor(
            $record->correlation->executionId,
            $record->fence->generation,
            'nonce-1',
            'other-scope',
            occurrence: $record->occurrence,
        );

        self::assertFalse(ExecutionLedgerRecord::launchDescriptorMatches($descriptor, $record->occurrence, $record->correlation, $record->fence));
        self::assertTrue(ExecutionLedgerRecord::launchDescriptorMatches(null, $record->occurrence, $record->correlation, $record->fence));
    }

    public function testQueryPortExposesObservationHistoryAndActiveRecords(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);

        self::assertInstanceOf(ExecutionLedgerQueryInterface::class, $ledger);
        self::assertSame($record, $ledger->observe($record->occurrence));
        self::assertTrue($ledger->hasTaskHistory('task'));
        self::assertSame([$record], $ledger->activeExecutions('source-1'));
    }

    public function testCommandPortExposesExplicitIndexRepairOperation(): void
    {
        $ledger = new FakeExecutionLedger();

        self::assertInstanceOf(ExecutionLedgerCommandInterface::class, $ledger);
        $ledger->repairIndexes();
    }

    public function testAcknowledgementIsReturnedAfterCommitAndDuplicateIsIdempotent(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $first = $ledger->admit($record);
        self::assertTrue($ledger->committed);
        self::assertTrue($first->newlyAdmitted);
        $duplicate = $ledger->admit(ledgerRecord(ExecutionLedgerState::RUNNING, 'different-manifest'));
        self::assertTrue($duplicate->isExisting());
        self::assertSame($first->record, $duplicate->record);
        self::assertSame(ExecutionLedgerState::LAUNCH_REQUIRED, $ledger->observe($record->occurrence)?->state);
    }

    public function testClaimAndTerminalWritesRejectStaleFenceAndTerminalCannotBeOverwritten(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $missing = $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::FAILED), 10);
        self::assertSame(ExecutionTerminalResult::MISSING, $missing->status);
        self::assertNull($missing->record);
        $ledger->admit($record);
        self::assertFalse($ledger->claim($record->occurrence, new ExecutionFence(2, 'wrong'), 11, 31)->accepted);
        $claim = $ledger->claim($record->occurrence, $record->fence, 11, 31);
        self::assertTrue($claim->accepted);
        $stale = $ledger->recordOutcome($record->occurrence, new ExecutionFence(2, 'wrong'), new ExecutionOutcome(ExecutionOutcome::FAILED), 12);
        self::assertSame(ExecutionTerminalResult::STALE_FENCED, $stale->status);
        self::assertNull($stale->record);
        $terminal = $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 12);
        self::assertSame(ExecutionTerminalResult::ACCEPTED, $terminal->status);
        self::assertSame(ExecutionLedgerState::SUCCEEDED, $terminal->record?->state);
        $alreadyTerminal = $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::FAILED, 'late'), 13);
        self::assertSame(ExecutionTerminalResult::ALREADY_TERMINAL, $alreadyTerminal->status);
        self::assertNull($alreadyTerminal->record);
    }

    public function testUnlimitedClaimRemainsWritableAfterArbitraryTimeAndStillRequiresFence(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);

        $claim = $ledger->claim($record->occurrence, $record->fence, 11, null);
        self::assertTrue($claim->accepted);
        self::assertNull($claim->record?->claimLeaseDeadline);

        $stale = $ledger->recordOutcome($record->occurrence, new ExecutionFence(2, 'wrong'), new ExecutionOutcome(ExecutionOutcome::FAILED), PHP_INT_MAX);
        self::assertSame(ExecutionTerminalResult::STALE_FENCED, $stale->status);
        $terminal = $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), PHP_INT_MAX);
        self::assertSame(ExecutionTerminalResult::ACCEPTED, $terminal->status);
    }

    public function testExpiredClaimLeaseDoesNotReleaseOwnershipOrMakeExecutionRedispatchable(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);
        self::assertTrue($ledger->claim($record->occurrence, $record->fence, 11, 20)->accepted);

        self::assertSame(ExecutionRecoveryResult::STALE, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 20_000_000, new ExecutionFence(2, 'wrong'), 'source-1')->status);
        self::assertSame(ExecutionRecoveryResult::LEASE_EXPIRED_OBSERVED, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 20_000_000, $record->fence, 'source-1')->status);
        self::assertSame(ExecutionDispatchResult::REJECTED, $ledger->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionDispatchReservation('dispatcher', 21, 30), 21_000_000, 'source-1')->status);
        self::assertSame(ExecutionRevocationResult::REJECTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 21_000_000, $record->fence, 'source-1')->status);
        self::assertSame(ExecutionGenerationResult::REQUIRES_REVOCATION, $ledger->allocateGeneration($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionFence(2, 'fence-2'), 21_000_000, $record->fence, 'source-1')->status);
        self::assertSame(ExecutionLedgerState::CLAIMED, $ledger->observe($record->occurrence)?->state);
        self::assertSame(ExecutionTerminalResult::REJECTED, $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 20)->status);
    }

    public function testExpiredClaimLeaseRejectsTerminalWriteWithoutMutation(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);
        $claim = $ledger->claim($record->occurrence, $record->fence, 11, 20);
        self::assertTrue($claim->accepted);
        $claimed = $claim->record;
        self::assertNotNull($claimed);

        foreach ([20, 21] as $at) {
            $rejected = $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), $at);
            self::assertSame(ExecutionTerminalResult::REJECTED, $rejected->status);
            self::assertSame('claim lease has expired', $rejected->reason);
            self::assertSame($claimed, $ledger->observe($record->occurrence));
        }
    }

    public function testSeparateIncompleteLaunchFactsRemainAmbiguous(): void
    {
        $observations = [
            new ExecutionLaunchObservation(null, null, 'pid-missing', 12, ExecutionProcessClosure::unknown('pid-missing')),
            new ExecutionLaunchObservation(null, null, 'outcome-missing', 12, ExecutionProcessClosure::unknown('outcome-missing')),
            new ExecutionLaunchObservation(null, 'bounded launch error', 'error', 12, ExecutionProcessClosure::unknown('bounded launch error')),
        ];
        foreach ($observations as $observation) {
            $ledger = new FakeExecutionLedger();
            $record = ledgerRecord(mode: 'async');
            $ledger->admit($record);
            $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher-1', 11, 20));
            $ambiguous = $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), $observation, 'source-1');
            self::assertSame(ExecutionDispatchResult::AMBIGUOUS, $ambiguous->status);
            self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $ledger->observe($record->occurrence)?->state);
        }

        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord(mode: 'async');
        $ledger->admit($record);
        $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher-1', 11, 20));
        $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), $observations[0], 'source-1');
        $claim = $ledger->claim($record->occurrence, $record->fence, 13, 30);
        self::assertFalse($claim->accepted, 'Async claims require an exact complete launch receipt.');

        $recoveryLedger = new FakeExecutionLedger();
        $recoveryLedger->admit($record);
        $recoveryLedger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher-1', 11, 20));
        $recoveryLedger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), $observations[0], 'source-1');
        self::assertSame(ExecutionDispatchResult::REJECTED, $recoveryLedger->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionDispatchReservation('dispatcher-2', 13, 22), 13_000_000, 'source-1')->status);
    }

    public function testSameGenerationRedispatchIsRejectedAfterClaimedAndRunning(): void
    {
        $claimed = new FakeExecutionLedger();
        $record = ledgerRecord();
        $claimed->admit($record);
        $claimed->claim($record->occurrence, $record->fence, 11, 31);
        self::assertSame(ExecutionDispatchResult::REJECTED, $claimed->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionDispatchReservation('dispatcher-1', 12, 32), 32_000_000, 'source-1')->status);

        $running = new FakeExecutionLedger();
        $runningRecord = ledgerRecord(ExecutionLedgerState::RUNNING);
        $running->admit($runningRecord);
        self::assertSame(ExecutionDispatchResult::REJECTED, $running->redispatchSameGeneration($runningRecord->occurrence, $runningRecord->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionDispatchReservation('dispatcher-1', 12, 32), 32_000_000, 'source-1')->status);
    }

    public function testUnknownClosureNeedsResolutionAndCannotBeRevoked(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);
        $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher', 10, 20));
        $observation = $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionLaunchObservation(null, null, 'unknown', 11, ExecutionProcessClosure::unknown('unknown')), 'source-1');

        self::assertSame(ExecutionDispatchResult::AMBIGUOUS, $observation->status);
        self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $ledger->observe($record->occurrence)?->state);
        self::assertSame(ExecutionRevocationResult::STALE, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('wrong', 1), 12_000_000, $record->fence, 'source-1')->status);
        self::assertSame(ExecutionRevocationResult::REJECTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 13_000_000, $record->fence, 'source-1')->status);
        self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $ledger->observe($record->occurrence)?->state);
    }

    public function testConfirmedClosureAllowsOnlyExplicitFencedRevocationAndKeepsOutcomeSeparate(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);
        $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher', 10, 20));
        $observed = $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionLaunchObservation(null, null, 'closed', 11, ExecutionProcessClosure::confirmed(0, 'closed')), 'source-1');

        self::assertSame(ExecutionDispatchResult::AMBIGUOUS, $observed->status);
        self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $observed->record?->state);
        self::assertNull($observed->record?->outcome);
        self::assertSame(ExecutionRevocationResult::STALE, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('wrong', 1), 12_000_000, $record->fence, 'source-1')->status);
        self::assertSame(ExecutionRevocationResult::ACCEPTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 13_000_000, $record->fence, 'source-1')->status);
        self::assertSame(ExecutionRecoveryResult::REVOKED, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 13_000_000, $record->fence, 'source-1')->status);
    }

    public function testRevocationFencesOldClaimAndOutcomeAndRecoveryReportsRevoked(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);
        self::assertSame(ExecutionRevocationResult::ACCEPTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 20_000_000, $record->fence, 'source-1')->status);
        self::assertSame(ExecutionRecoveryResult::REVOKED, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 20_000_000, $record->fence, 'source-1')->status);
        self::assertFalse($ledger->claim($record->occurrence, $record->fence, 21, 31)->accepted);
        self::assertSame(ExecutionTerminalResult::STALE_FENCED, $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 21)->status);
        $ledger->allocateGeneration($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionFence(2, 'fence-2'), 22_000_000, $record->fence, 'source-1');
        self::assertFalse($ledger->claim($record->occurrence, $record->fence, 23, 31)->accepted);
        self::assertSame(ExecutionTerminalResult::STALE_FENCED, $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::FAILED), 23)->status);
    }

    public function testLaunchFactsCannotOverwriteClaimedOrTerminalAndExpiredReservationCanRedispatch(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);
        $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher-1', 11, 20));
        self::assertSame(ExecutionDispatchResult::SAME_GENERATION_UNCLAIMED, $ledger->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionDispatchReservation('dispatcher-2', 21, 30), 20_000_000, 'source-1')->status);
        self::assertTrue($ledger->claim($record->occurrence, $record->fence, 22, 32)->accepted);
        self::assertSame(ExecutionDispatchResult::REJECTED, $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionLaunchObservation(null, 'dispatcher crashed', 'error', 23, ExecutionProcessClosure::unknown('dispatcher crashed')), 'source-1')->status);
        self::assertSame(ExecutionRecoveryResult::REJECTED, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 23_000_000, $record->fence, 'source-1')->status);
    }

    public function testTerminalOutcomeIsRejectedForEveryUnclaimedState(): void
    {
        foreach ([ExecutionLedgerState::LAUNCH_REQUIRED, ExecutionLedgerState::LAUNCH_REQUESTED, ExecutionLedgerState::LAUNCH_AMBIGUOUS] as $state) {
            $ledger = new FakeExecutionLedger();
            $record = ledgerRecord();
            $ledger->admit($record);
            if ($state === ExecutionLedgerState::LAUNCH_REQUESTED) {
                $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher-1', 11, 20));
            } elseif ($state === ExecutionLedgerState::LAUNCH_AMBIGUOUS) {
                $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionLaunchObservation(null, null, 'unknown', 11, ExecutionProcessClosure::unknown('unknown')), 'source-1');
            }
            self::assertSame(ExecutionTerminalResult::REJECTED, $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::FAILED), 12)->status);
        }
    }

    public function testLaunchObservationIsRejectedAfterRunningTerminalAndRevoked(): void
    {
        $observation = new ExecutionLaunchObservation(null, 'late', 'error', 30, ExecutionProcessClosure::unknown('late'));

        $running = new FakeExecutionLedger();
        $runningRecord = ledgerRecord(ExecutionLedgerState::RUNNING);
        $running->admit($runningRecord);
        self::assertSame(ExecutionDispatchResult::REJECTED, $running->observeLaunch($runningRecord->occurrence, $runningRecord->fence, new ExecutionRecoveryFence('recovery-1', 1), $observation, 'source-1')->status);

        $terminal = new FakeExecutionLedger();
        $terminalRecord = ledgerRecord();
        $terminal->admit($terminalRecord);
        $terminal->claim($terminalRecord->occurrence, $terminalRecord->fence, 11, 31);
        $terminal->recordOutcome($terminalRecord->occurrence, $terminalRecord->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 12);
        self::assertCount(1, $terminal->ownershipHoldingExecutions('source-1'));
        self::assertSame(ExecutionDispatchResult::ACCEPTED, $terminal->observeLaunch($terminalRecord->occurrence, $terminalRecord->fence, new ExecutionRecoveryFence('recovery-1', 1), $observation, 'source-1')->status);
        self::assertCount(1, $terminal->ownershipHoldingExecutions('source-1'));

        $revoked = new FakeExecutionLedger();
        $revokedRecord = ledgerRecord();
        $revoked->admit($revokedRecord);
        $revoked->revoke($revokedRecord->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 20_000_000, $revokedRecord->fence, 'source-1');
        self::assertSame(ExecutionDispatchResult::REJECTED, $revoked->observeLaunch($revokedRecord->occurrence, $revokedRecord->fence, new ExecutionRecoveryFence('recovery-1', 1), $observation, 'source-1')->status);
    }

    public function testGenerationRequiresRevocationAndRecoveryFenceIsIndependent(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord(started: true, mode: 'async');
        $ledger->admit($record);
        self::assertSame(ExecutionGenerationResult::REQUIRES_REVOCATION, $ledger->allocateGeneration($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionFence(2, 'fence-2'), 20_000_000, $record->fence, 'source-1')->status);
        self::assertSame(ExecutionRevocationResult::ACCEPTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), 21_000_000, $record->fence, 'source-1')->status);
        $allocated = $ledger->allocateGeneration($record->occurrence, new ExecutionRecoveryFence('recovery-1', 1), new ExecutionFence(2, 'fence-2'), 22_000_000, $record->fence, 'source-1');
        self::assertSame(ExecutionGenerationResult::ALLOCATED, $allocated->status);
        self::assertEquals($record->processIdentity, $allocated->record?->processIdentity);
        self::assertEquals($record->startObservation, $allocated->record?->startObservation);
        self::assertSame($record->recoveryAge, $allocated->record?->recoveryAge);
        self::assertSame($record->recoveryDeadline, $allocated->record?->recoveryDeadline);
        $generation2RecoveryFence = $allocated->record?->recoveryFence;
        self::assertNotNull($generation2RecoveryFence);
        $generation2Fence = new ExecutionFence(2, 'fence-2');
        self::assertSame(ExecutionRevocationResult::ACCEPTED, $ledger->revoke($record->occurrence, $generation2RecoveryFence, 23_000_000, $generation2Fence, 'source-1')->status);
        self::assertSame(ExecutionGenerationResult::REJECTED, $ledger->allocateGeneration($record->occurrence, $generation2RecoveryFence, $generation2Fence, 24_000_000, $generation2Fence, 'source-1')->status);
        self::assertSame(ExecutionGenerationResult::REJECTED, $ledger->allocateGeneration($record->occurrence, $generation2RecoveryFence, new ExecutionFence(1, 'fence-1'), 25_000_000, $generation2Fence, 'source-1')->status);
    }

    public function testDistinctOccurrencesAreStoredIndependently(): void
    {
        $ledger = new FakeExecutionLedger();
        $first = ledgerRecord();
        $second = ledgerRecord(occurrenceId: 'occurrence-2');

        $ledger->admit($first);
        $ledger->admit($second);

        self::assertSame($first, $ledger->observe($first->occurrence));
        self::assertSame($second, $ledger->observe($second->occurrence));
    }

    public function testTerminalRejectionReasonsUseTheBoundedReasonRule(): void
    {
        self::assertSame(ExecutionTerminalResult::REJECTED, ExecutionTerminalResult::rejected('invalid')->status);
        foreach (['', str_repeat('x', 4097)] as $reason) {
            try {
                ExecutionTerminalResult::rejected($reason);
                self::fail('Expected bounded terminal rejection reason to be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testClaimRejectionReasonsUseTheBoundedReasonRule(): void
    {
        foreach (['', str_repeat('x', 4097)] as $reason) {
            try {
                ExecutionClaimResult::rejected($reason);
                self::fail('Expected bounded claim rejection reason to be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame('stale', ExecutionClaimResult::rejected('stale')->reason);
    }

    public function testAmbiguityResolutionAndBoundedScalarFactsAreRepresentable(): void
    {
        $record = ledgerRecord(ExecutionLedgerState::NEEDS_RESOLUTION, 'manifest-v1', ['operator' => 'required']);
        self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $record->state);
        self::assertSame('required', $record->metadata['operator']);
        $this->expectException(InvalidArgumentException::class);
        new ExecutionOutcome(ExecutionOutcome::FAILED, str_repeat('x', 4097));
    }
}

final class FakeExecutionLedger implements ExecutionLedgerCommandInterface, ExecutionLedgerQueryInterface
{
    public bool $committed = false;
    /** @var array<string, ExecutionLedgerRecord> */
    private array $records = [];

    public function markRecoveryExpired(
        ExecutionOccurrenceKey $occurrence,
        ExecutionFence $expectedFence,
        ExecutionRecoveryFence $recoveryFence,
        int $nowUs,
        string $sourceIdentity,
    ): ExecutionRecoveryResult {
        $record = $this->observe($occurrence);
        if ($record === null || !$record->fence->equals($expectedFence) || !$this->recoveryMatches($record, $recoveryFence, $sourceIdentity)) {
            return ExecutionRecoveryResult::result(ExecutionRecoveryResult::STALE);
        }
        if ($record->isTerminal()) {
            return ExecutionRecoveryResult::terminal($record);
        }
        if ($record->state === ExecutionLedgerState::RECOVERY_EXPIRED && $record->recoveryDisposition === ExecutionLedgerState::RECOVERY_EXPIRED) {
            return ExecutionRecoveryResult::expired($record);
        }
        $updated = $record->transition(state: ExecutionLedgerState::RECOVERY_EXPIRED, updatedAt: $nowUs, recoveryDisposition: ExecutionLedgerState::RECOVERY_EXPIRED);
        $this->records[(string) $occurrence] = $updated;
        return ExecutionRecoveryResult::expired($updated);
    }

    public function admit(ExecutionLedgerRecord $record): ExecutionAdmission
    {
        $key = (string) $record->occurrence;
        if (isset($this->records[$key])) {
            return new ExecutionAdmission($this->records[$key], false);
        }
        $this->records[$key] = $record;
        $this->committed = true;
        return new ExecutionAdmission($record, true);
    }

    public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
    {
        return $this->records[(string) $occurrence] ?? null;
    }

    public function hasTaskHistory(string $taskKey): bool
    {
        foreach ($this->records as $record) {
            if ($record->occurrence->taskKey === $taskKey && $record->occurrence->taskKey === $taskKey) {
                return true;
            }
        }
        return false;
    }

    /** @return list<ExecutionLedgerRecord> */
    public function activeExecutions(string $sourceIdentity): array
    {
        return array_values(array_filter($this->records, static fn(ExecutionLedgerRecord $record): bool => !$record->isTerminal() && $record->sourceIdentity === $sourceIdentity));
    }

    public function ownershipHoldingExecutions(string $sourceIdentity): array
    {
        return array_values(array_filter($this->records, static fn(ExecutionLedgerRecord $record): bool => $record->state !== ExecutionLedgerState::REVOKED
            && (!$record->isTerminal() || $record->launchObservation?->closure?->isConfirmed() !== true)
            && $record->sourceIdentity === $sourceIdentity));
    }

    public function recoveryPage(string $sourceIdentity, ?string $cursor, int $limit): ExecutionLedgerRecoveryPage
    {
        return new ExecutionLedgerRecoveryPage([], null);
    }

    public function repairIndexes(): void {}

    public function claim(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, int $at, ?int $claimLeaseDeadline, ?ExecutionLaunchDescriptor $descriptor = null, ?string $permissionId = null): ExecutionClaimResult
    {
        $record = $this->observe($occurrence);
        $async = ($record?->taskSnapshot['task']['mode'] ?? null) === 'async';
        $receiptRequired = $async || $descriptor !== null || $permissionId !== null;
        if ($receiptRequired && ($descriptor === null || $permissionId === null)) {
            return ExecutionClaimResult::rejected('worker receipt is incomplete');
        }
        if ($record === null || !$record->fence->equals($fence) || $record->isTerminal() || !in_array($record->state, [ExecutionLedgerState::LAUNCH_REQUIRED, ExecutionLedgerState::LAUNCH_REQUESTED, ExecutionLedgerState::NEEDS_RESOLUTION], true) || ($claimLeaseDeadline !== null && $claimLeaseDeadline < $at)) {
            return ExecutionClaimResult::rejected('stale or unavailable execution');
        }
        if ($receiptRequired && ($record->dispatchReservation?->permissionId !== $permissionId
            || $record->dispatchReservation->launchPhase !== ExecutionDispatchReservation::OBSERVED
            || $record->launchDescriptor === null || !$record->launchDescriptor->equals($descriptor)
            || $record->launchObservation?->processIdentity === null || $record->launchObservation->startObservation === null
            || $record->launchObservation->pid !== $record->launchObservation->processIdentity->pid
            || $record->startAnchorIdentity === null || $record->startObservation === null || $record->recoveryDeadline === null)) {
            return ExecutionClaimResult::rejected('complete worker launch receipt is required');
        }
        $claimed = $record->transition(state: ExecutionLedgerState::CLAIMED, updatedAt: $at, outcome: null, claimLeaseDeadline: $claimLeaseDeadline);
        $this->records[(string) $occurrence] = $claimed;
        return ExecutionClaimResult::accepted($claimed);
    }

    public function launchUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $d, string $p, int $at, callable $start, int $nowUs): ExecutionDispatchResult
    {
        throw new LogicException();
    }

    public function prepareCommandUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $rootDescriptor, ExecutionLaunchDescriptor $commandDescriptor, int $at, int $nowUs): ExecutionDispatchResult
    {
        return ExecutionDispatchResult::stale();
    }

    public function launchCommandUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $rootDescriptor, ExecutionCommandScope $preparedScope, int $at, callable $start, int $nowUs): ExecutionDispatchResult
    {
        return ExecutionDispatchResult::stale();
    }

    public function observeCommandUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $rootDescriptor, ExecutionLaunchDescriptor $commandDescriptor, ExecutionLaunchObservation $observation): ExecutionDispatchResult
    {
        return ExecutionDispatchResult::stale();
    }

    public function repairBeforeStartUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, callable $absenceProof, int $nowUs): ExecutionDispatchResult
    {
        throw new LogicException();
    }

    public function rotateAfterClosureUnderLock(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $r, ExecutionFence $n, int $nowUs, ExecutionFence $e, string $s): ExecutionGenerationResult
    {
        throw new LogicException();
    }

    public function reserveDispatch(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionDispatchReservation $reservation): ExecutionDispatchResult
    {
        $record = $this->observe($occurrence);
        if ($record === null || !$record->fence->equals($fence)) {
            return ExecutionDispatchResult::stale();
        }
        if ($record->dispatchReservation !== null || $record->state !== ExecutionLedgerState::LAUNCH_REQUIRED) {
            return ExecutionDispatchResult::alreadyLaunched($record);
        }
        $updated = $record->transition(state: ExecutionLedgerState::LAUNCH_REQUESTED, updatedAt: $reservation->reservedAt, outcome: null, dispatchReservation: $reservation, launchObservation: null);
        $this->records[(string) $occurrence] = $updated;
        return ExecutionDispatchResult::accepted($updated);
    }

    public function redispatchSameGeneration(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, ExecutionDispatchReservation $reservation, int $nowUs, string $sourceIdentity): ExecutionDispatchResult
    {
        $dispatchAt = intdiv($nowUs, 1_000_000);
        $record = $this->observe($occurrence);
        if ($record === null || !$record->fence->equals($expectedFence) || !$this->recoveryMatches($record, $recoveryFence, $sourceIdentity)) {
            return ExecutionDispatchResult::stale();
        }
        if ($record->recoveryDeadline !== null && $nowUs >= $record->recoveryDeadline) {
            return ExecutionDispatchResult::rejected('recovery deadline has elapsed');
        }
        if (in_array($record->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING], true)) {
            return ExecutionDispatchResult::rejected('claimed execution cannot be redispatched');
        }
        if ($record->isTerminal() || $record->state === ExecutionLedgerState::REVOKED) {
            return ExecutionDispatchResult::stale('terminal or revoked execution');
        }
        if ($record->state !== ExecutionLedgerState::LAUNCH_REQUIRED && ($record->state !== ExecutionLedgerState::LAUNCH_REQUESTED || $record->dispatchReservation === null || $record->dispatchReservation->deadline > $dispatchAt)) {
            return ExecutionDispatchResult::rejected('dispatch reservation is still live');
        }
        if ($reservation->launchPhase !== ExecutionDispatchReservation::PREPARED || $reservation->deadline <= $dispatchAt) {
            return ExecutionDispatchResult::rejected('dispatch permission is due or consumed');
        }
        $updated = $record->transition(state: ExecutionLedgerState::LAUNCH_REQUESTED, updatedAt: $dispatchAt, outcome: null, dispatchReservation: $reservation, launchObservation: null, revokedAt: null);
        $this->records[(string) $occurrence] = $updated;
        return ExecutionDispatchResult::sameGenerationUnclaimed($updated);
    }

    public function observeLaunch(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, ExecutionLaunchObservation $observation, string $sourceIdentity): ExecutionDispatchResult
    {
        $record = $this->observe($occurrence);
        if ($record === null) {
            return ExecutionDispatchResult::stale();
        }
        if (!$record->fence->equals($expectedFence) || !$this->recoveryMatches($record, $recoveryFence, $sourceIdentity)) {
            return ExecutionDispatchResult::stale();
        }
        if ($observation->processIdentity === null && $observation->startObservation !== null) {
            return ExecutionDispatchResult::rejected('start facts require process identity');
        }
        if ($observation->pid !== null && $observation->processIdentity !== null && $observation->pid !== $observation->processIdentity->pid) {
            return ExecutionDispatchResult::rejected('launch observation PID conflicts with process identity');
        }
        if (($record->processIdentity !== null && $observation->processIdentity !== null && $record->processIdentity != $observation->processIdentity)
            || ($record->startObservation !== null && $observation->startObservation !== null && $record->startObservation != $observation->startObservation)) {
            return ExecutionDispatchResult::rejected('conflicting process identity facts');
        }
        if ($record->launchObservation !== null && $this->sameDurableObservation($record->launchObservation, $observation)) {
            return ExecutionDispatchResult::accepted($record);
        }
        if ($record->launchObservation?->closure?->isConfirmed() === true
            && $observation->closure?->isConfirmed() === true
            && $record->launchObservation->closure != $observation->closure) {
            return ExecutionDispatchResult::rejected('conflicting process closure facts');
        }
        // Process observations are independent facts and may arrive after a
        // claim or terminal outcome. Merge them without changing business state.
        if ($record->isTerminal() || $record->state === ExecutionLedgerState::RECOVERY_EXPIRED
            || in_array($record->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING], true)) {
            if ($observation->processIdentity === null && $observation->closure?->isConfirmed() !== true) {
                return $record->isTerminal() || $record->state === ExecutionLedgerState::RECOVERY_EXPIRED
                    ? ExecutionDispatchResult::accepted($record)
                    : ExecutionDispatchResult::rejected('process facts require a confirmed observation');
            }
            $existing = $record->launchObservation;
            $identity = $observation->processIdentity ?? $record->processIdentity ?? $existing?->processIdentity;
            $start = $observation->startObservation ?? $record->startObservation ?? $existing?->startObservation;
            $closure = $observation->closure?->isConfirmed() === true ? $observation->closure : $existing?->closure;
            $merged = $existing === null
                ? new ExecutionLaunchObservation($observation->pid, $observation->error, $observation->classification, $observation->observedAt, $closure, $observation->launchHandle, $identity, $start)
                : new ExecutionLaunchObservation($existing->pid, $existing->error, $existing->classification, $existing->observedAt, $closure, $existing->launchHandle, $identity, $start);
            $updated = $record->transition(updatedAt: $observation->observedAt, launchObservation: $merged, processIdentity: $identity, startObservation: $start);
            $this->records[(string) $occurrence] = $updated;
            return ExecutionDispatchResult::accepted($updated);
        }
        if (!in_array($record->state, [ExecutionLedgerState::LAUNCH_REQUESTED, ExecutionLedgerState::LAUNCH_AMBIGUOUS, ExecutionLedgerState::NEEDS_RESOLUTION], true) || $record->dispatchReservation === null) {
            return ExecutionDispatchResult::rejected('launch observation requires a dispatch reservation');
        }
        $existing = $record->launchObservation;
        $closure = $existing?->closure?->isConfirmed() === true && $observation->closure?->isConfirmed() !== true
            ? $existing->closure : ($observation->closure ?? $existing?->closure);
        $merged = new ExecutionLaunchObservation(
            $observation->pid ?? $existing?->pid,
            $observation->error,
            $observation->classification,
            max($existing?->observedAt ?? 0, $observation->observedAt),
            $closure,
            $observation->launchHandle ?? $existing?->launchHandle,
            $observation->processIdentity ?? $existing?->processIdentity,
            $observation->startObservation ?? $existing?->startObservation,
        );
        $reservation = $record->dispatchReservation;
        if ($reservation->launchPhase === ExecutionDispatchReservation::PREPARED) {
            $reservation = $reservation->withPhase(ExecutionDispatchReservation::AMBIGUOUS);
        } elseif ($reservation->launchPhase === ExecutionDispatchReservation::STARTING && $merged->processIdentity !== null && $merged->startObservation !== null) {
            $reservation = $reservation->withPhase(ExecutionDispatchReservation::OBSERVED);
        }
        $updated = $record->transition(state: ExecutionLedgerState::NEEDS_RESOLUTION, updatedAt: $observation->observedAt, outcome: null, dispatchReservation: $reservation, launchObservation: $merged, processIdentity: $merged->processIdentity, startObservation: $merged->startObservation);
        $this->records[(string) $occurrence] = $updated;
        return ExecutionDispatchResult::ambiguous($updated);
    }

    public function recover(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $recoveryFence, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionRecoveryResult
    {
        $record = $this->observe($occurrence);
        if ($record === null || !$record->fence->equals($expectedFence) || !$this->recoveryMatches($record, $recoveryFence, $sourceIdentity)) {
            return ExecutionRecoveryResult::result(ExecutionRecoveryResult::STALE);
        }
        if ($record->recoveryDeadline !== null && $nowUs >= $record->recoveryDeadline) {
            return ExecutionRecoveryResult::result(ExecutionRecoveryResult::RECOVERY_EXPIRED, $record, 'recovery deadline has elapsed');
        }
        if ($record->isTerminal()) {
            return ExecutionRecoveryResult::terminal($record);
        }
        if ($record->state === ExecutionLedgerState::REVOKED) {
            return ExecutionRecoveryResult::result(ExecutionRecoveryResult::REVOKED, $record);
        }
        if ($record->state === ExecutionLedgerState::LAUNCH_REQUIRED || ($record->state === ExecutionLedgerState::LAUNCH_REQUESTED && $record->dispatchReservation !== null && $record->dispatchReservation->launchPhase === ExecutionDispatchReservation::PREPARED && $record->dispatchReservation->deadline <= $nowUs)) {
            return ExecutionRecoveryResult::sameGenerationUnclaimed($record);
        }
        if (in_array($record->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING], true)) {
            $at = intdiv($nowUs, 1_000_000);
            return $record->claimLeaseDeadline !== null && $record->claimLeaseDeadline <= $at
                ? ExecutionRecoveryResult::leaseExpiredObserved($record)
                : ExecutionRecoveryResult::result(ExecutionRecoveryResult::REJECTED, $record, 'claim lease is still live');
        }
        return ExecutionRecoveryResult::result(ExecutionRecoveryResult::NEEDS_RESOLUTION, $record);
    }

    public function revoke(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $recoveryFence, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionRevocationResult
    {
        $record = $this->observe($occurrence);
        if ($record === null || !$record->fence->equals($expectedFence) || !$this->recoveryMatches($record, $recoveryFence, $sourceIdentity)) {
            return ExecutionRevocationResult::stale();
        }
        if ($record->isTerminal()) {
            return ExecutionRevocationResult::rejected('terminal outcome is authoritative');
        }
        if ($record->revokedAt !== null) {
            return ExecutionRevocationResult::alreadyRevoked($record);
        }
        if ($record->state !== ExecutionLedgerState::LAUNCH_REQUIRED && $record->launchObservation?->closure?->isConfirmed() !== true) {
            return ExecutionRevocationResult::rejected('process closure is not confirmed');
        }
        $updated = $record->transition(state: ExecutionLedgerState::REVOKED, updatedAt: $nowUs, outcome: null, revokedAt: $nowUs);
        $this->records[(string) $occurrence] = $updated;
        return ExecutionRevocationResult::accepted($updated);
    }

    public function allocateGeneration(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $recoveryFence, ExecutionFence $newFence, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionGenerationResult
    {
        $record = $this->observe($occurrence);
        if ($record === null || !$record->fence->equals($expectedFence) || !$this->recoveryMatches($record, $recoveryFence, $sourceIdentity)) {
            return ExecutionGenerationResult::stale();
        }
        if ($record->isTerminal()) {
            return ExecutionGenerationResult::rejected('terminal outcome is authoritative');
        }
        if ($record->revokedAt === null) {
            return ExecutionGenerationResult::requiresRevocation($record);
        }
        if (in_array($record->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING, ExecutionLedgerState::LAUNCH_AMBIGUOUS, ExecutionLedgerState::NEEDS_RESOLUTION], true) && $record->launchObservation?->closure?->isConfirmed() !== true) {
            return ExecutionGenerationResult::rejected('process closure is not confirmed');
        }
        if ($newFence->generation <= $record->fence->generation) {
            return ExecutionGenerationResult::rejected('generation must increase strictly');
        }
        $updated = $record->transition(state: ExecutionLedgerState::LAUNCH_REQUIRED, updatedAt: $nowUs, outcome: null, attempt: $record->attempt + 1, fence: $newFence, dispatchReservation: null, launchObservation: null, recoveryFence: new ExecutionRecoveryFence('recovery-2', $newFence->generation), revokedAt: null, launchDescriptor: null, claimLeaseDeadline: null);
        $this->records[(string) $occurrence] = $updated;
        return ExecutionGenerationResult::allocated($newFence, $updated);
    }

    public function recordOutcome(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionOutcome $outcome, int $at): ExecutionTerminalResult
    {
        $record = $this->observe($occurrence);
        if ($record === null) {
            return ExecutionTerminalResult::missing();
        }
        if (!$record->fence->equals($fence) || $record->state === ExecutionLedgerState::REVOKED || $record->revokedAt !== null) {
            return ExecutionTerminalResult::staleFenced();
        }
        if ($record->isTerminal()) {
            return ExecutionTerminalResult::alreadyTerminal();
        }
        if (!in_array($record->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING, ExecutionLedgerState::NEEDS_RESOLUTION, ExecutionLedgerState::RECOVERY_EXPIRED], true)) {
            return ExecutionTerminalResult::rejected('terminal outcome requires a claim or running transition');
        }
        if (($record->state === ExecutionLedgerState::NEEDS_RESOLUTION || $record->state === ExecutionLedgerState::RECOVERY_EXPIRED)
            && ($record->claimLeaseDeadline === null || $at >= $record->claimLeaseDeadline)) {
            return ExecutionTerminalResult::rejected('accepted claim lease is absent or expired');
        }
        if ($record->state !== ExecutionLedgerState::RECOVERY_EXPIRED && $record->claimLeaseDeadline !== null && $at >= $record->claimLeaseDeadline) {
            return ExecutionTerminalResult::rejected('claim lease has expired');
        }
        $updated = $record->transition(state: $outcome->status, updatedAt: $at, outcome: $outcome, claimLeaseDeadline: null);
        $this->records[(string) $occurrence] = $updated;
        return ExecutionTerminalResult::accepted($updated);
    }

    private function sameDurableObservation(ExecutionLaunchObservation $left, ExecutionLaunchObservation $right): bool
    {
        return $left->pid === $right->pid
            && $left->error === $right->error
            && $left->classification === $right->classification
            && $left->observedAt === $right->observedAt
            && $left->closure == $right->closure
            && $left->processIdentity == $right->processIdentity
            && $left->startObservation == $right->startObservation;
    }

    private function recoveryMatches(ExecutionLedgerRecord $record, ExecutionRecoveryFence $fence, string $sourceIdentity): bool
    {
        return $record->recoveryFence !== null
            && $record->recoveryFence->equals($fence)
            && $record->sourceIdentity === $sourceIdentity;
    }
}

function ledgerRecord(string $state = ExecutionLedgerState::LAUNCH_REQUIRED, string $configurationFingerprint = 'manifest-v1', array $metadata = [], string $occurrenceId = 'occurrence', bool $started = false, string $mode = 'sync'): ExecutionLedgerRecord
{
    $key = new ExecutionOccurrenceKey('task', $occurrenceId);
    $processIdentity = $started ? new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id') : null;
    return new ExecutionLedgerRecord($key, new ExecutionIdentity('task', $occurrenceId, 'attempt-1', 'run-1', 'execution-1'), 1, new ExecutionFence(1, 'fence-1'), $state, $configurationFingerprint, ['schema' => 'dev', 'source' => ['source-1', 'revision-1'], 'task' => ['id' => 'task', 'mode' => $mode, 'timeout' => null, 'recoveryMaxAge' => $mode === 'async' ? 180.0 : null, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]], 10, 10, null, $metadata, null, null, new ExecutionRecoveryFence('recovery-1', 1), sourceIdentity: 'source-1', sourceRevision: 'revision-1', processIdentity: $processIdentity, startAnchorIdentity: $processIdentity, startObservation: $started ? new ExecutionStartObservation(1700000000, 123456789) : null, recoveryAge: $started ? 180.0 : null, recoveryDeadline: $started ? 1880000000 : null);
}
