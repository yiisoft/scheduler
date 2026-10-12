<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use Psr\Clock\ClockInterface;
use Throwable;
use UnexpectedValueException;

/** Framework-neutral, single-use fresh-worker invocation service. */
final class ExecutionWorkerEntrypoint
{
    public function __construct(
        private readonly ExecutionLedgerLocatorInterface $ledgerLocator,
        private readonly ExecutionWorkerRuntimeFactoryInterface $factory,
        private readonly ClockInterface $clock,
    ) {}

    /** The transport is deliberately not decoded by an application bootstrap or CLI. */
    public function invoke(mixed $encodedCapability, ?int $claimLeaseDeadline): ExecutionWorkerInvocationResult
    {
        try {
            $capability = ExecutionWorkerCapability::decode($encodedCapability);
        } catch (Throwable) {
            return new ExecutionWorkerInvocationResult(ExecutionWorkerInvocationResult::INVALID_CAPABILITY, 'worker.invalid_capability');
        }

        try {
            $ledger = $this->ledgerLocator->resolve($capability->ledgerLocator);
        } catch (Throwable) {
            return new ExecutionWorkerInvocationResult(ExecutionWorkerInvocationResult::LOCATOR_UNAVAILABLE, 'worker.ledger_unavailable');
        }

        $claimAt = $this->clock->now()->getTimestamp();
        try {
            $runtime = (new ExecutionWorkerRuntimeComposer($ledger, $this->factory))
                ->compose($capability, $claimAt, $claimLeaseDeadline);
        } catch (ExecutionWorkerClaimRejectedException $e) {
            if ($e->getCode() === ExecutionWorkerRuntimeComposer::POST_CLAIM_MISMATCH) {
                $outcome = new ExecutionOutcome(ExecutionOutcome::FAILED, 'worker.capability_mismatch_after_claim');
                return $this->terminalize($ledger, $capability, $claimAt, $outcome, true);
            }
            return new ExecutionWorkerInvocationResult(ExecutionWorkerInvocationResult::CLAIM_REJECTED, 'worker.claim_rejected');
        } catch (Throwable) {
            $runtime = null;
            $outcome = new ExecutionOutcome(ExecutionOutcome::FAILED, 'worker.runtime_factory_failed');
            return $this->terminalize($ledger, $capability, $claimAt, $outcome, true);
        }

        try {
            $outcome = $runtime->execute();
            if (!$outcome instanceof ExecutionOutcome) {
                throw new UnexpectedValueException();
            }
        } catch (Throwable) {
            $outcome = new ExecutionOutcome(ExecutionOutcome::FAILED, 'worker.runtime_failed');
            return $this->terminalize($ledger, $capability, $claimAt, $outcome, true);
        }

        if ($outcome->status === ExecutionOutcome::UNRESOLVED) {
            return new ExecutionWorkerInvocationResult(
                ExecutionWorkerInvocationResult::UNRESOLVED_OWNERSHIP,
                'worker.ownership_unresolved',
                $outcome,
            );
        }

        return $this->terminalize($ledger, $capability, $claimAt, $outcome, false);
    }

    private function terminalize(ExecutionLedgerInterface $ledger, ExecutionWorkerCapability $capability, int $claimAt, ExecutionOutcome $outcome, bool $postClaimFailure): ExecutionWorkerInvocationResult
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
                    $outcome = $ledger->observe($capability->occurrence)?->outcome;
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
