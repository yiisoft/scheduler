<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

/**
 * Backend-neutral port for one durable execution ledger.
 * Implementations must make admit atomic by occurrence key and fence every
 * claim and terminal mutation with the currently active generation/capability.
 */
interface ExecutionLedgerInterface
{
    /** The returned acknowledgement exists only after durable admission commits. */
    public function admit(ExecutionLedgerRecord $record): ExecutionAdmission;

    public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord;

    /** Whether any admitted execution snapshot exists for this task. */
    public function hasTaskHistory(string $scheduleKey, string $taskKey): bool;

    /** The claim lease is independent from any dispatch reservation deadline. */
    public function claim(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, int $at, ?int $claimLeaseDeadline): ExecutionClaimResult;

    public function reserveDispatch(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionDispatchReservation $reservation): ExecutionDispatchResult;

    /** Reuses the current generation only as an explicit recovery action after expiry or ambiguity. */
    public function redispatchSameGeneration(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionDispatchReservation $reservation, int $at): ExecutionDispatchResult;

    public function observeLaunch(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionLaunchObservation $observation): ExecutionDispatchResult;

    public function recover(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $fence, int $at): ExecutionRecoveryResult;

    public function revoke(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $fence, int $at): ExecutionRevocationResult;

    /** Allocate a generation only after the record has been explicitly revoked. */
    public function allocateGeneration(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $fence, ExecutionFence $newFence, int $at): ExecutionGenerationResult;

    /**
     * Record a terminal outcome when the fence is current, the record is non-terminal,
     * and the deterministic observation time is before its independent claim lease deadline.
     *
     * The result distinguishes an accepted transition from missing, stale-fenced,
     * already-terminal, and rejected writes; rejected writes must not alter the durable record.
     * Revocation, fence, and terminal precedence must be preserved before lease expiry rejection.
     */
    public function recordOutcome(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionOutcome $outcome, int $at): ExecutionTerminalResult;
}
