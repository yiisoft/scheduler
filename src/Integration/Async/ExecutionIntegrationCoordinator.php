<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;
use Throwable;
use Yiisoft\Scheduler\Config\PreparedConfiguration;

use function hash;

use const PHP_INT_MAX;

/** Coordinates the package-neutral boundary between an occurrence source and a worker launcher. */
final class ExecutionIntegrationCoordinator
{
    private readonly ExecutionLedgerQueryInterface $query;

    public function __construct(
        private readonly ExecutionLedgerCommandInterface $ledger,
        private readonly ExecutionOccurrenceSourceInterface $source,
        private readonly ExecutionWorkerLauncherInterface $launcher,
        ExecutionLedgerQueryInterface $query,
        private readonly ?string $sourceIdentity = null,
    ) {
        $this->query = $query;
    }

    /**
     * Admit and dispatch the source's current candidate. A null result means
     * that the source has no candidate; a failed admission leaves the source untouched.
     */
    public function dispatchNext(
        ExecutionLedgerRecord $record,
        ExecutionWorkerCapability $capability,
        ExecutionDispatchReservation $reservation,
        ?WorkerLifecyclePolicy $lifecyclePolicy = null,
    ): ExecutionIntegrationDispatchResult {
        $candidate = $this->source->next();
        if ($candidate === null) {
            return new ExecutionIntegrationDispatchResult(null, null, null);
        }

        if (!$candidate->matchesRecord($record)) {
            throw new InvalidArgumentException('Execution record does not match the source candidate.');
        }

        // Validate the complete worker capability and its prepared launch
        // handoff before durable admission. A mismatched handoff must not
        // consume the source candidate or create a ledger admission.
        $this->assertCapability($capability, $record);
        $this->assertPreparedHandoff($capability, $reservation);

        $admission = $this->ledger->admit($record);
        if (!$candidate->matchesAdmission($admission)) {
            throw new InvalidArgumentException('Admitted execution does not match the source candidate.');
        }
        // The ledger may return an existing record, so retain the post-admission
        // validation before acknowledging or launching against that durable fact.
        $this->assertCapability($capability, $admission->record);
        $this->assertPreparedHandoff($capability, $reservation, $admission->record);

        // Acknowledge only after admission and before any dispatch side effect.
        $this->source->acknowledge($candidate, $admission);
        $dispatch = $this->ledger->reserveDispatch($admission->record->occurrence, $admission->record->fence, $reservation);
        if ($dispatch->status !== ExecutionDispatchResult::ACCEPTED) {
            return new ExecutionIntegrationDispatchResult($admission, $dispatch, null);
        }

        $request = new ExecutionWorkerLaunchRequest($capability, $lifecyclePolicy);
        // Commit the bounded descriptor before command construction or external
        // process start. This makes the pre-start crash window recoverable.
        if ($this->ledger instanceof ExecutionLaunchDescriptorLedgerInterface) {
            $recoveryFence = $this->recoveryFence($admission->record);
            $handoff = $this->ledger->recordLaunchDescriptor(
                $admission->record->occurrence,
                $admission->record->fence,
                $recoveryFence,
                $request->descriptor,
                $this->sourceIdentity($admission->record),
            );
            if ($handoff->status !== ExecutionDispatchResult::ACCEPTED) {
                return new ExecutionIntegrationDispatchResult($admission, $handoff, null);
            }
        }
        // Consume the prepared permission and start exactly once while the
        // occurrence lock is held. The callback is deliberately limited to
        // the process-local launch and conversion of its typed receipt.
        $receipt = null;
        $nowUs = (int) round(microtime(true) * 1000000);
        $at = intdiv($nowUs, 1000000);
        $lockedDispatch = $this->ledger->launchUnderLock(
            $admission->record->occurrence,
            $admission->record->fence,
            $this->recoveryFence($admission->record),
            $this->sourceIdentity($admission->record),
            $request->descriptor,
            $reservation->permissionId,
            $at,
            function () use ($request, &$receipt, $at): ExecutionLaunchObservation {
                $receipt = $this->launcher->launch($request);
                return $this->launchObservation($receipt, $request->descriptor, $at);
            },
            nowUs: $nowUs,
        );
        return new ExecutionIntegrationDispatchResult($admission, $lockedDispatch, $lockedDispatch->status === ExecutionDispatchResult::ACCEPTED ? $receipt?->handle : null);
    }

    /**
     * Execute a process-local synchronous callable after durable admission and claim.
     * The claim lease is deliberately independent of command timeout policy: sync
     * commands have no enforced timeout.
     */
    public function dispatchSynchronous(
        ExecutionLedgerRecord $record,
        ?ExecutionWorkerCapability $capability,
        callable $deferred,
        int $at,
    ): ExecutionIntegrationDispatchResult {
        $candidate = $this->source->next();
        if ($candidate === null) {
            return new ExecutionIntegrationDispatchResult(null, null, null);
        }
        if (!$candidate->matchesRecord($record)) {
            throw new InvalidArgumentException('Execution record does not match the source candidate.');
        }
        if ($capability !== null) {
            $this->assertCapability($capability, $record);
        }
        $admission = $this->ledger->admit($record);
        if (!$candidate->matchesAdmission($admission)) {
            throw new InvalidArgumentException('Admitted execution does not match the source candidate.');
        }
        if ($capability !== null) {
            $this->assertCapability($capability, $admission->record);
        }
        $this->source->acknowledge($candidate, $admission);

        // PHP_INT_MAX is an explicit, independent lease rather than an enforced
        // execution timeout. It keeps the fenced terminal write valid for the
        // lifetime of this planner process.
        $claim = $this->ledger->claim(
            $admission->record->occurrence,
            $admission->record->fence,
            $at,
            PHP_INT_MAX,
        );
        if (!$claim->accepted || $claim->record === null) {
            return new ExecutionIntegrationDispatchResult($admission, ExecutionDispatchResult::rejected($claim->reason ?? 'claim rejected'), null);
        }

        try {
            $outcome = $deferred();
            if (!$outcome instanceof ExecutionOutcome) {
                throw new InvalidArgumentException('Synchronous callback must return an ExecutionOutcome.');
            }
        } catch (Throwable) {
            $outcome = new ExecutionOutcome(ExecutionOutcome::FAILED, 'callable.execution_failed');
        }
        $terminalAt = max($at, time());
        $terminal = $this->ledger->recordOutcome($admission->record->occurrence, $admission->record->fence, $outcome, $terminalAt);
        $dispatch = $terminal->record === null
            ? ExecutionDispatchResult::rejected('synchronous outcome was not committed')
            : ExecutionDispatchResult::accepted($terminal->record);
        return new ExecutionIntegrationDispatchResult($admission, $dispatch, null);
    }

    /**
     * Apply a bounded parent observation. A terminal ledger snapshot is authoritative
     * and deliberately bypasses the mutating observation operation.
     */
    public function observeLaunch(
        ExecutionOccurrenceKey $occurrence,
        ExecutionFence $fence,
        ExecutionLaunchObservation $observation,
    ): ExecutionDispatchResult {
        $current = $this->query->observe($occurrence);
        if ($current === null) {
            return ExecutionDispatchResult::stale('execution is missing');
        }
        // Even a non-mutating terminal read must prove the pinned source and
        // both durable capabilities before it can be treated as authoritative.
        $recoveryFence = $this->recoveryFence($current);
        $sourceIdentity = $this->sourceIdentity($current);
        if (!$current->fence->equals($fence)) {
            return ExecutionDispatchResult::stale('execution fence mismatch');
        }
        if ($current->isTerminal()) {
            // A confirmed closure is the one bounded fact that may complete a
            // terminal snapshot's ownership lifecycle. Unknown or mismatched
            // observations remain non-mutating terminal reads.
            if ($observation->closure?->isConfirmed() === true && $current->fence->equals($fence)) {
                return $this->ledger->observeLaunch($occurrence, $fence, $recoveryFence, $observation, $sourceIdentity);
            }
            return ExecutionDispatchResult::accepted($current);
        }
        return $this->ledger->observeLaunch($occurrence, $fence, $recoveryFence, $observation, $sourceIdentity);
    }

    /**
     * Dispatch a newly rotated generation without touching the occurrence source.
     * The caller has already proved closure and rotated the durable record.
     */
    public function dispatchRecoveryGeneration(
        ExecutionLedgerRecord $record,
        ExecutionWorkerCapability $capability,
        ExecutionDispatchReservation $reservation,
        int $nowUs,
        ?WorkerLifecyclePolicy $lifecyclePolicy = null,
    ): ExecutionIntegrationDispatchResult {
        $this->assertCapability($capability, $record);
        $this->assertPreparedHandoff($capability, $reservation, $record);
        $at = intdiv($nowUs, 1000000);
        // reserveDispatch is the durable claim-before-bootstrap boundary for a
        // fresh generation; launchUnderLock performs the final fenced claim
        // while publishing the receipt. (claim() for async records requires an
        // already observed receipt and is therefore not applicable here.)
        $dispatch = $this->ledger->reserveDispatch($record->occurrence, $record->fence, $reservation);
        if ($dispatch->status !== ExecutionDispatchResult::ACCEPTED) {
            return new ExecutionIntegrationDispatchResult(null, $dispatch, null);
        }
        $request = new ExecutionWorkerLaunchRequest($capability, $lifecyclePolicy);
        if ($this->ledger instanceof ExecutionLaunchDescriptorLedgerInterface) {
            $handoff = $this->ledger->recordLaunchDescriptor(
                $record->occurrence,
                $record->fence,
                $this->recoveryFence($record),
                $request->descriptor,
                $this->sourceIdentity($record),
            );
            if ($handoff->status !== ExecutionDispatchResult::ACCEPTED) {
                return new ExecutionIntegrationDispatchResult(null, $handoff, null);
            }
        }
        $receipt = null;
        $locked = $this->ledger->launchUnderLock(
            $record->occurrence,
            $record->fence,
            $this->recoveryFence($record),
            $this->sourceIdentity($record),
            $request->descriptor,
            $reservation->permissionId,
            $at,
            function () use ($request, &$receipt, $at): ExecutionLaunchObservation {
                $receipt = $this->launcher->launch($request);
                return $this->launchObservation($receipt, $request->descriptor, $at);
            },
            nowUs: $nowUs,
        );
        return new ExecutionIntegrationDispatchResult(null, $locked, $locked->status === ExecutionDispatchResult::ACCEPTED ? $receipt?->handle : null);
    }

    /** Relaunches only after recovery explicitly reports an unclaimed same generation. */
    public function redispatchSameGeneration(
        ExecutionRecoveryResult $recovery,
        ExecutionWorkerCapability $capability,
        ExecutionDispatchReservation $reservation,
        int $nowUs,
        ?WorkerLifecyclePolicy $lifecyclePolicy = null,
    ): ExecutionIntegrationDispatchResult {
        if ($recovery->status !== ExecutionRecoveryResult::SAME_GENERATION_UNCLAIMED || $recovery->record === null) {
            throw new InvalidArgumentException('Same-generation redispatch requires an unclaimed recovery result.');
        }
        $record = $recovery->record;
        $this->assertCapability($capability, $record);
        $dispatch = $this->ledger->redispatchSameGeneration($record->occurrence, $record->fence, $capability->recoveryFence, $reservation, $nowUs, $this->sourceIdentity($record));
        if ($dispatch->status !== ExecutionDispatchResult::SAME_GENERATION_UNCLAIMED) {
            return new ExecutionIntegrationDispatchResult(null, $dispatch, null);
        }
        // Recovery must launch with the descriptor already admitted for this
        // generation. Generating another nonce would make the durable scope
        // identity disagree with the process being relaunched.
        $request = new ExecutionWorkerLaunchRequest(
            $capability,
            $lifecyclePolicy,
            descriptor: $dispatch->record?->launchDescriptor ?? $record->launchDescriptor ?? $capability->launchDescriptor,
        );
        if ($this->ledger instanceof ExecutionLaunchDescriptorLedgerInterface) {
            $recoveryFence = $this->recoveryFence($record);
            $handoff = $this->ledger->recordLaunchDescriptor($record->occurrence, $record->fence, $recoveryFence, $request->descriptor, $this->sourceIdentity($record));
            if ($handoff->status !== ExecutionDispatchResult::ACCEPTED) {
                return new ExecutionIntegrationDispatchResult(null, $handoff, null);
            }
        }
        // Consume the replacement permission and start exactly once while the
        // occurrence lock is held. Recovery redispatch must use the same durable
        // receipt-publication boundary as the initial dispatch; launching after
        // this call would leave the ledger able to race a stale recovery.
        $receipt = null;
        $lockedDispatch = $this->ledger->launchUnderLock(
            $record->occurrence,
            $record->fence,
            $this->recoveryFence($record),
            $this->sourceIdentity($record),
            $request->descriptor,
            $reservation->permissionId,
            intdiv($nowUs, 1000000),
            function () use ($request, &$receipt, $nowUs): ExecutionLaunchObservation {
                $receipt = $this->launcher->launch($request);
                return $this->launchObservation($receipt, $request->descriptor, intdiv($nowUs, 1000000));
            },
            nowUs: $nowUs,
        );
        return new ExecutionIntegrationDispatchResult(null, $lockedDispatch, $lockedDispatch->status === ExecutionDispatchResult::ACCEPTED ? $receipt?->handle : null);
    }

    private function launchObservation(ExecutionWorkerLaunchReceipt $receipt, ExecutionLaunchDescriptor $expectedDescriptor, int $observedAt): ExecutionLaunchObservation
    {
        $launch = $receipt->processLaunchResult;
        $identity = $launch->identity;
        if ($identity === null || !$identity->descriptor->equals($expectedDescriptor) || $launch->startedAt === null || $launch->wallStartedAtMicros === null) {
            throw new InvalidArgumentException('Worker launch receipt is incomplete.');
        }
        return new ExecutionLaunchObservation(
            $identity->pid,
            null,
            'started',
            $observedAt,
            processIdentity: new ExecutionProcessIdentity(
                $identity->pid,
                $identity->processGroupId,
                $identity->sessionId,
                $identity->startTicks,
                $identity->bootId,
            ),
            launchHandle: $receipt->handle,
            startObservation: new ExecutionStartObservation(
                $launch->wallStartedAtMicros,
                (int) round($launch->startedAt * 1000000000),
            ),
        );
    }

    private function recoveryFence(ExecutionLedgerRecord $record): ExecutionRecoveryFence
    {
        if ($record->recoveryFence === null || $record->recoveryFence->generation !== $record->fence->generation) {
            throw new InvalidArgumentException('Execution recovery fence is missing or not generation-bound.');
        }
        return $record->recoveryFence;
    }

    private function sourceIdentity(ExecutionLedgerRecord $record): string
    {
        $source = $this->sourceIdentity;
        if ($source === null || $source === '' || $record->sourceIdentity !== $source) {
            throw new InvalidArgumentException('Execution source identity is missing or mismatched.');
        }
        return $source;
    }

    private function assertPreparedHandoff(
        ExecutionWorkerCapability $capability,
        ExecutionDispatchReservation $reservation,
        ?ExecutionLedgerRecord $record = null,
    ): void {
        if ($capability->launchDescriptor === null || $capability->permissionId === null
            || $capability->permissionId !== $reservation->permissionId
            || $reservation->launchPhase !== ExecutionDispatchReservation::PREPARED) {
            throw new InvalidArgumentException('Worker launch handoff does not match the prepared reservation.');
        }
        $preparedRecord = $record;
        if ($preparedRecord !== null) {
            $dispatchReservation = $preparedRecord->dispatchReservation;
            if ($dispatchReservation !== null && $dispatchReservation->permissionId !== $capability->permissionId) {
                throw new InvalidArgumentException('Worker launch handoff does not match the prepared reservation.');
            }
            $launchDescriptor = $preparedRecord->launchDescriptor;
            if ($launchDescriptor !== null && !$launchDescriptor->equals($capability->launchDescriptor)) {
                throw new InvalidArgumentException('Worker launch handoff does not match the prepared reservation.');
            }
        }
    }

    private function assertCapability(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $record): void
    {
        $correlation = $record->correlation;
        $recoveryFence = $record->recoveryFence;
        if ($recoveryFence === null || $recoveryFence->generation !== $record->fence->generation) {
            throw new InvalidArgumentException('Execution recovery fence is missing or not generation-bound.');
        }
        $snapshotVersion = hash('sha256', PreparedConfiguration::canonical($record->taskSnapshot));
        if (!$capability->occurrence->equals($record->occurrence)
            || $capability->attemptId !== $correlation->attemptId
            || $capability->runId !== $correlation->runId
            || $capability->executionId !== $correlation->executionId
            || $capability->generation !== $record->fence->generation
            || !hash_equals($capability->fenceToken, $record->fence->token)
            || $capability->configurationFingerprint !== $record->configurationFingerprint
            || $capability->taskVersion !== $snapshotVersion
            || $capability->policy !== $record->taskSnapshot
            || ($record->launchDescriptor !== null
                && ($capability->launchDescriptor === null || !$capability->launchDescriptor->equals($record->launchDescriptor)))
            || !$capability->recoveryFence->equals($recoveryFence)
            || ($record->sourceIdentity !== null && ($this->sourceIdentity ?? $record->sourceIdentity) !== $record->sourceIdentity)
        ) {
            throw new InvalidArgumentException('Worker capability does not match the admitted execution.');
        }
    }
}
