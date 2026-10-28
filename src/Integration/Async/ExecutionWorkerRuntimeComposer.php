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

    private readonly ExecutionLedgerQueryInterface $query;

    public function __construct(
        private readonly ExecutionLedgerCommandInterface $ledger,
        private readonly ExecutionWorkerRuntimeFactoryInterface $factory,
        ?ExecutionLedgerQueryInterface $query = null,
    ) {
        /** @var ExecutionLedgerQueryInterface $resolvedQuery */
        $resolvedQuery = $query ?? $ledger;
        $this->query = $resolvedQuery;
    }

    public function compose(
        ExecutionWorkerCapability $capability,
        int $at,
        ?int $claimLeaseDeadline,
        ?ExecutionLaunchDescriptor $descriptor = null,
        ?string $permissionId = null,
    ): ExecutionWorkerRuntimeInterface {
        // These are the bounded handoff facts published by the root
        // dispatcher. Never manufacture them from current configuration: the
        // ledger is authoritative for the exact admitted values and rejects
        // missing or mismatched facts atomically.
        if ($capability->launchDescriptor === null || $capability->permissionId === null
            || ($descriptor !== null && !$descriptor->equals($capability->launchDescriptor))
            || ($permissionId !== null && $permissionId !== $capability->permissionId)) {
            throw new ExecutionWorkerClaimRejectedException('Worker launch handoff is missing or mismatched.');
        }
        $descriptor = $capability->launchDescriptor;
        $permissionId = $capability->permissionId;
        try {
            $observed = $this->query->observe($capability->occurrence);
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
                $descriptor,
                $permissionId,
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
        return $this->factory->create($capability, $record, $this->ledger);
    }

    private function isAvailable(ExecutionLedgerRecord $record): bool
    {
        return !$record->isTerminal()
            && $record->state !== ExecutionLedgerState::REVOKED
            && in_array($record->state, [
                ExecutionLedgerState::LAUNCH_REQUIRED,
                ExecutionLedgerState::LAUNCH_REQUESTED,
                ExecutionLedgerState::NEEDS_RESOLUTION,
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
            && $record->taskSnapshot === $capability->policy
            && $record->sourceIdentity === ($capability->policy['source'][0] ?? null)
            && $record->sourceRevision === $capability->sourceRevision
            && $record->recoveryFence !== null
            && $record->recoveryFence->equals($capability->recoveryFence)
            && ($record->recoveryAge === null
                || $record->recoveryAge === ($capability->policy['task']['recoveryMaxAge'] ?? null));
    }
}
