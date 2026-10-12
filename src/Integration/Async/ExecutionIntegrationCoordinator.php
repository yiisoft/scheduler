<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use InvalidArgumentException;
use Throwable;
use Yiisoft\Schedule\Config\PreparedConfiguration;

use function hash;

use const PHP_INT_MAX;

/** Coordinates the package-neutral boundary between an occurrence source and a worker launcher. */
final class ExecutionIntegrationCoordinator
{
    public function __construct(
        private readonly ExecutionLedgerInterface $ledger,
        private readonly ExecutionOccurrenceSourceInterface $source,
        private readonly ExecutionWorkerLauncherInterface $launcher,
    ) {}

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

        // Validate the complete worker capability against the source-bound record
        // before durable admission. A mismatched capability must not consume the
        // source candidate or create a ledger admission.
        $this->assertCapability($capability, $record);

        $admission = $this->ledger->admit($record);
        if (!$candidate->matchesAdmission($admission)) {
            throw new InvalidArgumentException('Admitted execution does not match the source candidate.');
        }
        // The ledger may return an existing record, so retain the post-admission
        // validation before acknowledging or launching against that durable fact.
        $this->assertCapability($capability, $admission->record);

        // Acknowledge only after admission and before any dispatch side effect.
        $this->source->acknowledge($candidate, $admission);
        $dispatch = $this->ledger->reserveDispatch($admission->record->occurrence, $admission->record->fence, $reservation);
        if ($dispatch->status !== ExecutionDispatchResult::ACCEPTED) {
            return new ExecutionIntegrationDispatchResult($admission, $dispatch, null);
        }

        $handle = $this->launcher->launch(new ExecutionWorkerLaunchRequest($capability, $lifecyclePolicy));
        return new ExecutionIntegrationDispatchResult($admission, $dispatch, $handle);
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
        $current = $this->ledger->observe($occurrence);
        if ($current !== null && $current->isTerminal()) {
            // A confirmed closure is the one bounded fact that may complete a
            // terminal snapshot's ownership lifecycle. Unknown or mismatched
            // observations remain non-mutating terminal reads.
            if ($observation->closure?->isConfirmed() === true && $current->fence->equals($fence)) {
                return $this->ledger->observeLaunch($occurrence, $fence, $observation);
            }
            return ExecutionDispatchResult::accepted($current);
        }
        return $this->ledger->observeLaunch($occurrence, $fence, $observation);
    }

    /** Relaunches only after recovery explicitly reports an unclaimed same generation. */
    public function redispatchSameGeneration(
        ExecutionRecoveryResult $recovery,
        ExecutionWorkerCapability $capability,
        ExecutionDispatchReservation $reservation,
        int $at,
        ?WorkerLifecyclePolicy $lifecyclePolicy = null,
    ): ExecutionIntegrationDispatchResult {
        if ($recovery->status !== ExecutionRecoveryResult::SAME_GENERATION_UNCLAIMED || $recovery->record === null) {
            throw new InvalidArgumentException('Same-generation redispatch requires an unclaimed recovery result.');
        }
        $record = $recovery->record;
        $this->assertCapability($capability, $record);
        $dispatch = $this->ledger->redispatchSameGeneration($record->occurrence, $record->fence, $reservation, $at);
        if ($dispatch->status !== ExecutionDispatchResult::SAME_GENERATION_UNCLAIMED) {
            return new ExecutionIntegrationDispatchResult(null, $dispatch, null);
        }
        return new ExecutionIntegrationDispatchResult(null, $dispatch, $this->launcher->launch(new ExecutionWorkerLaunchRequest($capability, $lifecyclePolicy)));
    }

    private function assertCapability(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $record): void
    {
        $correlation = $record->correlation;
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
        ) {
            throw new InvalidArgumentException('Worker capability does not match the admitted execution.');
        }
    }
}
