<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;
use Throwable;
use Yiisoft\Scheduler\Config\PreparedConfiguration;

use function hash;
use function in_array;

/** Fresh-worker composition: decode/validate, claim with the active fence, then bootstrap. */
final class ExecutionWorkerRuntimeComposer
{
    /** Distinguishes an accepted claim whose returned record cannot be trusted. */
    public const POST_CLAIM_MISMATCH = 1001;

    public function __construct(
        private readonly ExecutionLedgerInterface $ledger,
        private readonly ExecutionWorkerRuntimeFactoryInterface $factory,
    ) {}

    public function compose(ExecutionWorkerCapability $capability, int $at, ?int $claimLeaseDeadline): ExecutionWorkerRuntimeInterface
    {
        try {
            $observed = $this->ledger->observe($capability->occurrence);
        } catch (Throwable $e) {
            throw new ExecutionWorkerClaimRejectedException('Worker capability could not be checked.', 0, $e);
        }
        try {
            PreparedConfiguration::assertTaskPolicyProjection($capability->policy);
            if ($observed !== null) {
                PreparedConfiguration::assertTaskPolicyProjection($observed->taskSnapshot);
            }
            if (($capability->policy['task']['mode'] ?? null) !== 'async'
                || ($observed !== null && ($observed->taskSnapshot['task']['mode'] ?? null) !== 'async')) {
                throw new InvalidArgumentException('Worker task policy is not asynchronous.');
            }
        } catch (Throwable $e) {
            throw new ExecutionWorkerClaimRejectedException('Worker task policy is invalid.', 0, $e);
        }
        if ($observed === null || !$this->isAvailable($observed) || !$this->matchesCapability($observed, $capability)) {
            throw new ExecutionWorkerClaimRejectedException('Worker capability is stale, unavailable, or not asynchronous.');
        }

        if ($this->factory instanceof ExecutionWorkerPreClaimValidatorInterface) {
            try {
                $this->factory->validate($capability, $observed);
            } catch (Throwable $e) {
                throw new ExecutionWorkerClaimRejectedException('Worker pre-claim validation failed.', 0, $e);
            }
        }

        try {
            $claim = $this->ledger->claim(
                $capability->occurrence,
                new ExecutionFence($capability->generation, $capability->fenceToken),
                $at,
                $claimLeaseDeadline,
            );
        } catch (Throwable $e) {
            throw new ExecutionWorkerClaimRejectedException('Fresh worker claim could not be established.', 0, $e);
        }
        if (!$claim->accepted || $claim->record === null) {
            throw new ExecutionWorkerClaimRejectedException('Fresh worker claim was rejected; application bootstrap was not invoked.');
        }
        $record = $claim->record;
        if (!$this->matchesCapability($record, $capability)
            || $record->state === ExecutionLedgerState::REVOKED
            || $record->isTerminal()) {
            throw new ExecutionWorkerClaimRejectedException(
                'Worker claim identity does not match its capability.',
                self::POST_CLAIM_MISMATCH,
            );
        }
        return $this->factory->create($capability, $record);
    }

    private function isAvailable(ExecutionLedgerRecord $record): bool
    {
        return !$record->isTerminal()
            && $record->state !== ExecutionLedgerState::REVOKED
            && in_array($record->state, [
                ExecutionLedgerState::LAUNCH_REQUIRED,
                ExecutionLedgerState::LAUNCH_REQUESTED,
            ], true);
    }

    private function matchesCapability(ExecutionLedgerRecord $record, ExecutionWorkerCapability $capability): bool
    {
        $taskVersion = hash('sha256', PreparedConfiguration::canonical($record->taskSnapshot));
        return $record->occurrence->equals($capability->occurrence)
            && $record->correlation->attemptId === $capability->attemptId
            && $record->correlation->runId === $capability->runId
            && $record->correlation->executionId === $capability->executionId
            && $record->fence->equals(new ExecutionFence($capability->generation, $capability->fenceToken))
            && $record->configurationFingerprint === $capability->configurationFingerprint
            && $taskVersion === $capability->taskVersion
            && $record->taskSnapshot === $capability->policy;
    }
}
