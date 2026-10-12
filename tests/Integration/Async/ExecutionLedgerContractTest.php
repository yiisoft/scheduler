<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\{
    ExecutionAdmission, ExecutionClaimResult, ExecutionDispatchReservation, ExecutionDispatchResult,
    ExecutionFence, ExecutionGenerationResult, ExecutionIdentity, ExecutionLaunchObservation,
    ExecutionLedgerInterface, ExecutionLedgerQueryInterface, ExecutionLedgerRecord, ExecutionLedgerState, ExecutionOccurrenceKey,
    ExecutionOutcome, ExecutionProcessClosure, ExecutionRecoveryFence, ExecutionRecoveryResult,
    ExecutionRevocationResult, ExecutionTerminalResult,
};

use function in_array;

use const PHP_INT_MAX;

final class ExecutionLedgerContractTest extends TestCase
{
    public function testQueryPortExposesActiveRecordsAndExplicitRepairOperation(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);

        self::assertInstanceOf(ExecutionLedgerQueryInterface::class, $ledger);
        self::assertSame([$record], $ledger->activeExecutions());
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

        self::assertSame(ExecutionRecoveryResult::LEASE_EXPIRED_OBSERVED, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 20)->status);
        self::assertSame(ExecutionDispatchResult::REJECTED, $ledger->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher', 21, 30), 21)->status);
        self::assertSame(ExecutionRevocationResult::REJECTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 21)->status);
        self::assertSame(ExecutionGenerationResult::REQUIRES_REVOCATION, $ledger->allocateGeneration($record->occurrence, new ExecutionRecoveryFence('recovery-1'), new ExecutionFence(2, 'fence-2'), 21)->status);
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
            new ExecutionLaunchObservation(123, null, 'outcome-missing', 12, ExecutionProcessClosure::unknown('outcome-missing')),
            new ExecutionLaunchObservation(null, 'bounded launch error', 'error', 12, ExecutionProcessClosure::unknown('bounded launch error')),
        ];
        foreach ($observations as $observation) {
            $ledger = new FakeExecutionLedger();
            $record = ledgerRecord();
            $ledger->admit($record);
            $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher-1', 11, 20));
            $ambiguous = $ledger->observeLaunch($record->occurrence, $record->fence, $observation);
            self::assertSame(ExecutionDispatchResult::AMBIGUOUS, $ambiguous->status);
            self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $ledger->observe($record->occurrence)?->state);
        }

        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);
        $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher-1', 11, 20));
        $ledger->observeLaunch($record->occurrence, $record->fence, $observations[0]);
        $claim = $ledger->claim($record->occurrence, $record->fence, 13, 30);
        self::assertFalse($claim->accepted);
        self::assertNull($claim->record);
        self::assertFalse($ledger->claim($record->occurrence, $record->fence, 14, 40)->accepted);

        $recoveryLedger = new FakeExecutionLedger();
        $recoveryLedger->admit($record);
        $recoveryLedger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher-1', 11, 20));
        $recoveryLedger->observeLaunch($record->occurrence, $record->fence, $observations[0]);
        self::assertSame(ExecutionDispatchResult::REJECTED, $recoveryLedger->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher-2', 13, 22), 13)->status);
    }

    public function testSameGenerationRedispatchIsRejectedAfterClaimedAndRunning(): void
    {
        $claimed = new FakeExecutionLedger();
        $record = ledgerRecord();
        $claimed->admit($record);
        $claimed->claim($record->occurrence, $record->fence, 11, 31);
        self::assertSame(ExecutionDispatchResult::REJECTED, $claimed->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher-1', 12, 32), 32)->status);

        $running = new FakeExecutionLedger();
        $runningRecord = ledgerRecord(ExecutionLedgerState::RUNNING);
        $running->admit($runningRecord);
        self::assertSame(ExecutionDispatchResult::REJECTED, $running->redispatchSameGeneration($runningRecord->occurrence, $runningRecord->fence, new ExecutionDispatchReservation('dispatcher-1', 12, 32), 32)->status);
    }

    public function testUnknownClosureNeedsResolutionAndCannotBeRevoked(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);
        $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher', 10, 20));
        $observation = $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionLaunchObservation(null, null, 'unknown', 11, ExecutionProcessClosure::unknown('unknown')));

        self::assertSame(ExecutionDispatchResult::AMBIGUOUS, $observation->status);
        self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $ledger->observe($record->occurrence)?->state);
        self::assertSame(ExecutionRevocationResult::STALE, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('wrong'), 12)->status);
        self::assertSame(ExecutionRevocationResult::REJECTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 13)->status);
        self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $ledger->observe($record->occurrence)?->state);
    }

    public function testConfirmedClosureAllowsOnlyExplicitFencedRevocationAndKeepsOutcomeSeparate(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);
        $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher', 10, 20));
        $observed = $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionLaunchObservation(123, null, 'closed', 11, ExecutionProcessClosure::confirmed(0, 'closed')));

        self::assertSame(ExecutionDispatchResult::AMBIGUOUS, $observed->status);
        self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $observed->record?->state);
        self::assertNull($observed->record?->outcome);
        self::assertSame(ExecutionRevocationResult::STALE, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('wrong'), 12)->status);
        self::assertSame(ExecutionRevocationResult::ACCEPTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 13)->status);
        self::assertSame(ExecutionRecoveryResult::REVOKED, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 13)->status);
    }

    public function testRevocationFencesOldClaimAndOutcomeAndRecoveryReportsRevoked(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);
        self::assertSame(ExecutionRevocationResult::ACCEPTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 20)->status);
        self::assertSame(ExecutionRecoveryResult::REVOKED, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 20)->status);
        self::assertFalse($ledger->claim($record->occurrence, $record->fence, 21, 31)->accepted);
        self::assertSame(ExecutionTerminalResult::STALE_FENCED, $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 21)->status);
        $ledger->allocateGeneration($record->occurrence, new ExecutionRecoveryFence('recovery-1'), new ExecutionFence(2, 'fence-2'), 22);
        self::assertFalse($ledger->claim($record->occurrence, $record->fence, 23, 31)->accepted);
        self::assertSame(ExecutionTerminalResult::STALE_FENCED, $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::FAILED), 23)->status);
    }

    public function testLaunchFactsCannotOverwriteClaimedOrTerminalAndExpiredReservationCanRedispatch(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);
        $ledger->reserveDispatch($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher-1', 11, 20));
        self::assertSame(ExecutionDispatchResult::SAME_GENERATION_UNCLAIMED, $ledger->redispatchSameGeneration($record->occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher-2', 21, 30), 20)->status);
        self::assertTrue($ledger->claim($record->occurrence, $record->fence, 22, 32)->accepted);
        self::assertSame(ExecutionDispatchResult::REJECTED, $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionLaunchObservation(null, 'dispatcher crashed', 'error', 23, ExecutionProcessClosure::unknown('dispatcher crashed')))->status);
        self::assertSame(ExecutionRecoveryResult::REJECTED, $ledger->recover($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 23)->status);
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
                $ledger->observeLaunch($record->occurrence, $record->fence, new ExecutionLaunchObservation(null, null, 'unknown', 11, ExecutionProcessClosure::unknown('unknown')));
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
        self::assertSame(ExecutionDispatchResult::REJECTED, $running->observeLaunch($runningRecord->occurrence, $runningRecord->fence, $observation)->status);

        $terminal = new FakeExecutionLedger();
        $terminalRecord = ledgerRecord();
        $terminal->admit($terminalRecord);
        $terminal->claim($terminalRecord->occurrence, $terminalRecord->fence, 11, 31);
        $terminal->recordOutcome($terminalRecord->occurrence, $terminalRecord->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 12);
        self::assertSame(ExecutionDispatchResult::ACCEPTED, $terminal->observeLaunch($terminalRecord->occurrence, $terminalRecord->fence, $observation)->status);

        $revoked = new FakeExecutionLedger();
        $revokedRecord = ledgerRecord();
        $revoked->admit($revokedRecord);
        $revoked->revoke($revokedRecord->occurrence, new ExecutionRecoveryFence('recovery-1'), 20);
        self::assertSame(ExecutionDispatchResult::REJECTED, $revoked->observeLaunch($revokedRecord->occurrence, $revokedRecord->fence, $observation)->status);
    }

    public function testGenerationRequiresRevocationAndRecoveryFenceIsIndependent(): void
    {
        $ledger = new FakeExecutionLedger();
        $record = ledgerRecord();
        $ledger->admit($record);
        self::assertSame(ExecutionGenerationResult::REQUIRES_REVOCATION, $ledger->allocateGeneration($record->occurrence, new ExecutionRecoveryFence('recovery-1'), new ExecutionFence(2, 'fence-2'), 20)->status);
        self::assertSame(ExecutionRevocationResult::ACCEPTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 21)->status);
        self::assertSame(ExecutionGenerationResult::ALLOCATED, $ledger->allocateGeneration($record->occurrence, new ExecutionRecoveryFence('recovery-1'), new ExecutionFence(2, 'fence-2'), 22)->status);
        self::assertSame(ExecutionRevocationResult::ACCEPTED, $ledger->revoke($record->occurrence, new ExecutionRecoveryFence('recovery-1'), 23)->status);
        self::assertSame(ExecutionGenerationResult::REJECTED, $ledger->allocateGeneration($record->occurrence, new ExecutionRecoveryFence('recovery-1'), new ExecutionFence(2, 'fence-2'), 24)->status);
        self::assertSame(ExecutionGenerationResult::REJECTED, $ledger->allocateGeneration($record->occurrence, new ExecutionRecoveryFence('recovery-1'), new ExecutionFence(1, 'fence-1'), 25)->status);
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

final class FakeExecutionLedger implements ExecutionLedgerInterface, ExecutionLedgerQueryInterface
{
    public bool $committed = false;
    /** @var array<string, ExecutionLedgerRecord> */
    private array $records = [];

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
    public function activeExecutions(): array
    {
        return array_values(array_filter($this->records, static fn(ExecutionLedgerRecord $record): bool => !$record->isTerminal()));
    }

    public function ownershipHoldingExecutions(): array
    {
        return array_values(array_filter($this->records, static fn(ExecutionLedgerRecord $record): bool => $record->state !== ExecutionLedgerState::REVOKED));
    }

    public function repairIndexes(): void {}

    public function claim(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, int $at, ?int $claimLeaseDeadline): ExecutionClaimResult
    {
        $record = $this->observe($occurrence);
        if ($record === null || !$record->fence->equals($fence) || $record->isTerminal() || !in_array($record->state, [ExecutionLedgerState::LAUNCH_REQUIRED, ExecutionLedgerState::LAUNCH_REQUESTED], true) || ($claimLeaseDeadline !== null && $claimLeaseDeadline < $at)) {
            return ExecutionClaimResult::rejected('stale or unavailable execution');
        }
        $claimed = new ExecutionLedgerRecord($record->occurrence, $record->correlation, $record->attempt, $record->fence, ExecutionLedgerState::CLAIMED, $record->launchIntent, $record->configurationFingerprint, $record->taskSnapshot, $record->createdAt, $at, null, $record->metadata, $record->dispatchReservation, $record->launchObservation, $record->recoveryFence, $record->revokedAt, $claimLeaseDeadline);
        $this->records[(string) $occurrence] = $claimed;
        return ExecutionClaimResult::accepted($claimed);
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
        $updated = new ExecutionLedgerRecord($record->occurrence, $record->correlation, $record->attempt, $record->fence, ExecutionLedgerState::LAUNCH_REQUESTED, $record->launchIntent, $record->configurationFingerprint, $record->taskSnapshot, $record->createdAt, $reservation->reservedAt, null, $record->metadata, $reservation, null, $record->recoveryFence, $record->revokedAt);
        $this->records[(string) $occurrence] = $updated;
        return ExecutionDispatchResult::accepted($updated);
    }

    public function redispatchSameGeneration(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionDispatchReservation $reservation, int $at): ExecutionDispatchResult
    {
        $record = $this->observe($occurrence);
        if ($record === null || !$record->fence->equals($fence)) {
            return ExecutionDispatchResult::stale();
        }
        if (in_array($record->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING], true)) {
            return ExecutionDispatchResult::rejected('claimed execution cannot be redispatched');
        }
        if ($record->isTerminal() || $record->state === ExecutionLedgerState::REVOKED) {
            return ExecutionDispatchResult::stale('terminal or revoked execution');
        }
        if ($record->state !== ExecutionLedgerState::LAUNCH_REQUIRED && ($record->state !== ExecutionLedgerState::LAUNCH_REQUESTED || $record->dispatchReservation === null || $record->dispatchReservation->deadline > $at)) {
            return ExecutionDispatchResult::rejected('dispatch reservation is still live');
        }
        $updated = new ExecutionLedgerRecord($record->occurrence, $record->correlation, $record->attempt, $record->fence, ExecutionLedgerState::LAUNCH_REQUESTED, $record->launchIntent, $record->configurationFingerprint, $record->taskSnapshot, $record->createdAt, $at, null, $record->metadata, $reservation, null, $record->recoveryFence, null);
        $this->records[(string) $occurrence] = $updated;
        return ExecutionDispatchResult::sameGenerationUnclaimed($updated);
    }

    public function observeLaunch(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionLaunchObservation $observation): ExecutionDispatchResult
    {
        $record = $this->observe($occurrence);
        if ($record === null) {
            return ExecutionDispatchResult::stale();
        }
        if ($record->isTerminal()) {
            return ExecutionDispatchResult::accepted($record);
        }
        if (!$record->fence->equals($fence)) {
            return ExecutionDispatchResult::stale();
        }
        if (!in_array($record->state, [ExecutionLedgerState::LAUNCH_REQUESTED, ExecutionLedgerState::LAUNCH_AMBIGUOUS], true) || $record->dispatchReservation === null) {
            return ExecutionDispatchResult::rejected('launch observation requires a dispatch reservation');
        }
        $state = ExecutionLedgerState::NEEDS_RESOLUTION;
        $updated = new ExecutionLedgerRecord($record->occurrence, $record->correlation, $record->attempt, $record->fence, $state, $record->launchIntent, $record->configurationFingerprint, $record->taskSnapshot, $record->createdAt, $observation->observedAt, null, $record->metadata, $record->dispatchReservation, $observation, $record->recoveryFence, $record->revokedAt);
        $this->records[(string) $occurrence] = $updated;
        return ExecutionDispatchResult::ambiguous($updated);
    }

    public function recover(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $fence, int $at): ExecutionRecoveryResult
    {
        $record = $this->observe($occurrence);
        if ($record === null || $record->recoveryFence === null || !$record->recoveryFence->equals($fence)) {
            return ExecutionRecoveryResult::result(ExecutionRecoveryResult::STALE);
        }
        if ($record->isTerminal()) {
            return ExecutionRecoveryResult::terminal($record);
        }
        if ($record->state === ExecutionLedgerState::REVOKED) {
            return ExecutionRecoveryResult::result(ExecutionRecoveryResult::REVOKED, $record);
        }
        if ($record->state === ExecutionLedgerState::LAUNCH_REQUIRED || ($record->state === ExecutionLedgerState::LAUNCH_REQUESTED && $record->dispatchReservation !== null && $record->dispatchReservation->deadline <= $at)) {
            return ExecutionRecoveryResult::sameGenerationUnclaimed($record);
        }
        if (in_array($record->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING], true)) {
            return $record->claimLeaseDeadline !== null && $record->claimLeaseDeadline <= $at
                ? ExecutionRecoveryResult::leaseExpiredObserved($record)
                : ExecutionRecoveryResult::result(ExecutionRecoveryResult::REJECTED, $record, 'claim lease is still live');
        }
        return ExecutionRecoveryResult::result(ExecutionRecoveryResult::NEEDS_RESOLUTION, $record);
    }

    public function revoke(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $fence, int $at): ExecutionRevocationResult
    {
        $record = $this->observe($occurrence);
        if ($record === null || $record->recoveryFence === null || !$record->recoveryFence->equals($fence)) {
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
        $updated = new ExecutionLedgerRecord($record->occurrence, $record->correlation, $record->attempt, $record->fence, ExecutionLedgerState::REVOKED, $record->launchIntent, $record->configurationFingerprint, $record->taskSnapshot, $record->createdAt, $at, null, $record->metadata, $record->dispatchReservation, $record->launchObservation, $record->recoveryFence, $at, $record->claimLeaseDeadline);
        $this->records[(string) $occurrence] = $updated;
        return ExecutionRevocationResult::accepted($updated);
    }

    public function allocateGeneration(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $fence, ExecutionFence $newFence, int $at): ExecutionGenerationResult
    {
        $record = $this->observe($occurrence);
        if ($record === null || $record->recoveryFence === null || !$record->recoveryFence->equals($fence)) {
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
        $updated = new ExecutionLedgerRecord($record->occurrence, $record->correlation, $record->attempt + 1, $newFence, ExecutionLedgerState::LAUNCH_REQUIRED, $record->launchIntent, $record->configurationFingerprint, $record->taskSnapshot, $record->createdAt, $at, null, $record->metadata, null, null, $record->recoveryFence, null);
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
        if (!in_array($record->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING], true)) {
            return ExecutionTerminalResult::rejected('terminal outcome requires a claim or running transition');
        }
        if ($record->claimLeaseDeadline !== null && $at >= $record->claimLeaseDeadline) {
            return ExecutionTerminalResult::rejected('claim lease has expired');
        }
        $updated = new ExecutionLedgerRecord($record->occurrence, $record->correlation, $record->attempt, $record->fence, $outcome->status, $record->launchIntent, $record->configurationFingerprint, $record->taskSnapshot, $record->createdAt, $at, $outcome, $record->metadata, $record->dispatchReservation, $record->launchObservation, $record->recoveryFence, $record->revokedAt);
        $this->records[(string) $occurrence] = $updated;
        return ExecutionTerminalResult::accepted($updated);
    }
}

function ledgerRecord(string $state = ExecutionLedgerState::LAUNCH_REQUIRED, string $configurationFingerprint = 'manifest-v1', array $metadata = [], string $occurrenceId = 'occurrence'): ExecutionLedgerRecord
{
    $key = new ExecutionOccurrenceKey('task', $occurrenceId);
    return new ExecutionLedgerRecord($key, new ExecutionIdentity('task', $occurrenceId, 'attempt-1', 'run-1', 'execution-1'), 1, new ExecutionFence(1, 'fence-1'), $state, 'launch', $configurationFingerprint, ['format' => 4, 'source' => [null, null], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => null, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]], 10, 10, null, $metadata, null, null, new ExecutionRecoveryFence('recovery-1'));
}
