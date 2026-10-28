<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

/**
 * Backend-neutral port for durable execution-ledger mutations.
 * Implementations must make admit atomic by occurrence key and fence every
 * claim and terminal mutation with the currently active generation/capability.
 */
interface ExecutionLedgerCommandInterface
{
    /** The returned acknowledgement exists only after durable admission commits. */
    public function admit(ExecutionLedgerRecord $record): ExecutionAdmission;

    /** Atomically retain the same-generation record as recovery-expired under both fences. */
    public function markRecoveryExpired(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, int $nowUs, string $sourceIdentity): ExecutionRecoveryResult;

    /** The claim lease is independent from any dispatch reservation deadline. */
    public function claim(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, int $at, ?int $claimLeaseDeadline, ?ExecutionLaunchDescriptor $descriptor = null, ?string $permissionId = null): ExecutionClaimResult;

    /** Consume and complete one launch permission while holding the occurrence lock. */
    public function launchUnderLock(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, string $sourceIdentity, ExecutionLaunchDescriptor $descriptor, string $permissionId, int $at, callable $start, int $nowUs): ExecutionDispatchResult;

    /** Durably prepare a nested command permission without starting a process. */
    public function prepareCommandUnderLock(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, string $sourceIdentity, ExecutionLaunchDescriptor $rootDescriptor, ExecutionLaunchDescriptor $commandDescriptor, int $at, int $nowUs): ExecutionDispatchResult;

    public function launchCommandUnderLock(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, string $sourceIdentity, ExecutionLaunchDescriptor $rootDescriptor, ExecutionCommandScope $preparedScope, int $at, callable $start, int $nowUs): ExecutionDispatchResult;

    /** Merge one terminal command observation under the occurrence lock. */
    public function observeCommandUnderLock(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, string $sourceIdentity, ExecutionLaunchDescriptor $rootDescriptor, ExecutionLaunchDescriptor $commandDescriptor, ExecutionLaunchObservation $observation): ExecutionDispatchResult;

    /** Re-prepare only after a callback has durably proved complete pre-start absence. */
    public function repairBeforeStartUnderLock(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, string $sourceIdentity, callable $absenceProof, int $nowUs): ExecutionDispatchResult;

    public function rotateAfterClosureUnderLock(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $recoveryFence, ExecutionFence $newFence, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionGenerationResult;

    public function reserveDispatch(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionDispatchReservation $reservation): ExecutionDispatchResult;

    /** Reuses the current generation only as an explicit recovery action after expiry or ambiguity. */
    public function redispatchSameGeneration(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, ExecutionDispatchReservation $reservation, int $nowUs, string $sourceIdentity): ExecutionDispatchResult;

    /** Post-lookup launch facts are fenced by both the execution and recovery capabilities. */
    public function observeLaunch(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, ExecutionLaunchObservation $observation, string $sourceIdentity): ExecutionDispatchResult;

    public function recover(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $recoveryFence, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionRecoveryResult;

    public function revoke(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $recoveryFence, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionRevocationResult;

    /** Allocate a generation only after the record has been explicitly revoked. */
    public function allocateGeneration(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $recoveryFence, ExecutionFence $newFence, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionGenerationResult;

    /** Rebuilds lookup aids from authoritative retained snapshots. */
    public function repairIndexes(): void;

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
