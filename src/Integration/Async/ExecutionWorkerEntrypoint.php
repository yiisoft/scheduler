<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use Psr\Clock\ClockInterface;
use Throwable;
use UnexpectedValueException;

use function preg_match;

/** Framework-neutral, single-use fresh-worker invocation service. */
final class ExecutionWorkerEntrypoint
{
    public function __construct(
        private readonly ExecutionLedgerLocatorInterface $ledgerLocator,
        private readonly ExecutionWorkerRuntimeFactoryInterface $factory,
        private readonly ClockInterface $clock,
        private readonly ?ExecutionLedgerQueryInterface $query = null,
    ) {}

    /** The transport is deliberately not decoded by an application bootstrap or CLI. */
    public function invoke(
        mixed $encodedCapability,
        ?int $claimLeaseDeadline,
        mixed $launchDescriptor = null,
        mixed $permissionId = null,
    ): ExecutionWorkerInvocationResult {
        try {
            $capability = ExecutionWorkerCapability::decode($encodedCapability);
        } catch (Throwable) {
            return new ExecutionWorkerInvocationResult(ExecutionWorkerInvocationResult::INVALID_CAPABILITY, 'worker.invalid_capability');
        }

        // Descriptor and permission are deliberately supplied as bounded
        // launch-handoff facts, not reconstructed from configuration. The
        // capability may carry the encoded bootstrap facts for the fresh
        // process; the receipt itself remains process-local.
        $capabilityLaunchDescriptor = $capability->launchDescriptor;
        $capabilityPermissionId = $capability->permissionId;
        if ($capabilityLaunchDescriptor === null || $capabilityPermissionId === null
            || ($launchDescriptor !== null && (!$launchDescriptor instanceof ExecutionLaunchDescriptor
                || !$launchDescriptor->equals($capabilityLaunchDescriptor)))
            || ($permissionId !== null && $permissionId !== $capabilityPermissionId)) {
            return new ExecutionWorkerInvocationResult(ExecutionWorkerInvocationResult::INVALID_CAPABILITY, 'worker.invalid_launch_handoff');
        }
        $launchDescriptor = $capabilityLaunchDescriptor;
        $permissionId = $capabilityPermissionId;
        if (preg_match('/^[a-f0-9]{32}$/D', $permissionId) !== 1
            || $launchDescriptor->role !== ExecutionLaunchDescriptor::ROLE_WORKER
            || $launchDescriptor->scopeKind !== ExecutionLaunchDescriptor::SCOPE_KIND
            || $launchDescriptor->executionId !== $capability->executionId
            || $launchDescriptor->generation !== $capability->generation
            || $launchDescriptor->occurrence === null
            || !$launchDescriptor->occurrence->equals($capability->occurrence)) {
            return new ExecutionWorkerInvocationResult(ExecutionWorkerInvocationResult::INVALID_CAPABILITY, 'worker.invalid_launch_handoff');
        }

        try {
            $ledger = $this->ledgerLocator->resolve($capability->ledgerLocator);
        } catch (Throwable) {
            return new ExecutionWorkerInvocationResult(ExecutionWorkerInvocationResult::LOCATOR_UNAVAILABLE, 'worker.ledger_unavailable');
        }

        $query = $this->query;
        if ($query === null) {
            if (!$ledger instanceof ExecutionLedgerQueryInterface) {
                return new ExecutionWorkerInvocationResult(ExecutionWorkerInvocationResult::LOCATOR_UNAVAILABLE, 'worker.ledger_query_unavailable');
            }
            $query = $ledger;
        }

        $claimAt = $this->clock->now()->getTimestamp();
        try {
            $runtime = (new ExecutionWorkerRuntimeComposer($ledger, $this->factory, $query))
                ->compose($capability, $claimAt, $claimLeaseDeadline, $launchDescriptor, $permissionId);
        } catch (ExecutionWorkerClaimRejectedException $e) {
            if ($e->getCode() === ExecutionWorkerRuntimeComposer::POST_CLAIM_MISMATCH) {
                $outcome = new ExecutionOutcome(ExecutionOutcome::FAILED, 'worker.capability_mismatch_after_claim');
                return $this->terminalize($ledger, $query, $capability, $claimAt, $outcome, true);
            }
            return new ExecutionWorkerInvocationResult(ExecutionWorkerInvocationResult::CLAIM_REJECTED, 'worker.claim_rejected');
        } catch (Throwable) {
            $runtime = null;
            $outcome = new ExecutionOutcome(ExecutionOutcome::FAILED, 'worker.runtime_factory_failed');
            return $this->terminalize($ledger, $query, $capability, $claimAt, $outcome, true);
        }

        try {
            $outcome = $runtime->execute();
            if (!$outcome instanceof ExecutionOutcome) {
                throw new UnexpectedValueException();
            }
        } catch (Throwable) {
            $outcome = new ExecutionOutcome(ExecutionOutcome::FAILED, 'worker.runtime_failed');
            return $this->terminalize($ledger, $query, $capability, $claimAt, $outcome, true);
        }

        if ($outcome->status === ExecutionOutcome::UNRESOLVED) {
            return new ExecutionWorkerInvocationResult(
                ExecutionWorkerInvocationResult::UNRESOLVED_OWNERSHIP,
                'worker.ownership_unresolved',
                $outcome,
            );
        }

        return $this->terminalize($ledger, $query, $capability, $claimAt, $outcome, false);
    }

    private function terminalize(ExecutionLedgerCommandInterface $ledger, ExecutionLedgerQueryInterface $query, ExecutionWorkerCapability $capability, int $claimAt, ExecutionOutcome $outcome, bool $postClaimFailure): ExecutionWorkerInvocationResult
    {
        $terminalAt = max($claimAt, $this->clock->now()->getTimestamp());
        try {
            $result = $ledger->recordOutcome(
                $capability->occurrence,
                new ExecutionFence($capability->generation, $capability->fenceToken),
                $outcome,
                $terminalAt,
            );
        } catch (Throwable) {
            return new ExecutionWorkerInvocationResult(ExecutionWorkerInvocationResult::TERMINAL_AMBIGUOUS, 'worker.terminal_ambiguous', $outcome);
        }

        if ($result->status !== ExecutionTerminalResult::ACCEPTED) {
            $status = match ($result->status) {
                ExecutionTerminalResult::MISSING => ExecutionWorkerInvocationResult::TERMINAL_MISSING,
                ExecutionTerminalResult::STALE_FENCED => ExecutionWorkerInvocationResult::TERMINAL_STALE_FENCED,
                ExecutionTerminalResult::ALREADY_TERMINAL => ExecutionWorkerInvocationResult::TERMINAL_ALREADY_TERMINAL,
                default => ExecutionWorkerInvocationResult::TERMINAL_REJECTED,
            };

            // A duplicate is only successful when the durable terminal outcome says so.
            if ($result->status === ExecutionTerminalResult::ALREADY_TERMINAL) {
                try {
                    $terminal = $query->observe($capability->occurrence);
                    if ($terminal !== null
                        && $terminal->sourceIdentity !== null
                        && $terminal->sourceRevision !== null
                        && $terminal->sourceIdentity === ($capability->policy['source'][0] ?? null)
                        && $terminal->sourceRevision === $capability->sourceRevision
                        && ($terminal->taskSnapshot['source'][0] ?? null) === $terminal->sourceIdentity
                        && ($terminal->taskSnapshot['source'][1] ?? null) === $terminal->sourceRevision) {
                        $outcome = $terminal->outcome;
                    } else {
                        $outcome = null;
                    }
                } catch (Throwable) {
                    $outcome = null;
                }
            }
            return new ExecutionWorkerInvocationResult($status, 'worker.terminal_rejected', $outcome);
        }

        if ($postClaimFailure) {
            return new ExecutionWorkerInvocationResult(ExecutionWorkerInvocationResult::POST_CLAIM_FAILED, $outcome->reason, $outcome);
        }
        $status = match ($outcome->status) {
            ExecutionOutcome::SUCCEEDED => ExecutionWorkerInvocationResult::COMMITTED_SUCCEEDED,
            ExecutionOutcome::CANCELLED => ExecutionWorkerInvocationResult::COMMITTED_CANCELLED,
            default => ExecutionWorkerInvocationResult::COMMITTED_FAILED,
        };
        return new ExecutionWorkerInvocationResult($status, $outcome->reason, $outcome);
    }
}
