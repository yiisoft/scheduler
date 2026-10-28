<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use Yiisoft\Scheduler\Integration\Async\{ExecutionDispatchReservation, ExecutionLedgerQueryInterface, ExecutionProcessIdentity};
use Yiisoft\Scheduler\Integration\Async\Process\ProcessHandleInterface;
use Yiisoft\Scheduler\Integration\Async\Recovery\RecoveryProcessObserverInterface;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessScopeProof;

use function is_array;
use function is_string;

/** Resolves only a currently verified command scope for a retained attempt. */
final class LedgerCommandScopeProcessResolver implements CommandScopeProcessResolverInterface
{
    public function __construct(
        private readonly ExecutionLedgerQueryInterface $ledger,
        private readonly RecoveryProcessObserverInterface $observer,
    ) {}

    public function resolve(RetainedWorker $worker): ?ProcessHandleInterface
    {
        $rootIdentity = $worker->processIdentity;
        $capability = $worker->request->capability;
        $record = $capability->occurrence;
        $ledgerRecord = $this->ledger->observe($record);
        $scope = $ledgerRecord?->commandScope;
        $policySource = $capability->policy['source'] ?? null;
        if ($rootIdentity === null || $ledgerRecord === null || $scope === null
            // Bind every retained capability fact to the freshly observed record
            // before consulting the process observer (or signalling its handle).
            || !is_array($policySource) || array_keys($policySource) !== [0, 1]
            || !is_string($policySource[0]) || !is_string($policySource[1])
            || $policySource[0] !== $ledgerRecord->sourceIdentity
            || $policySource[1] !== $ledgerRecord->sourceRevision
            || $capability->sourceRevision !== $ledgerRecord->sourceRevision
            || $ledgerRecord->fence->generation !== $capability->generation
            || $ledgerRecord->fence->token !== $capability->fenceToken
            || $ledgerRecord->recoveryFence === null
            || $ledgerRecord->recoveryFence->generation !== $capability->recoveryFence->generation
            || $ledgerRecord->recoveryFence->token !== $capability->recoveryFence->token
            || $ledgerRecord->correlation->taskKey !== $capability->occurrence->taskKey
            || $ledgerRecord->correlation->occurrenceId !== $capability->occurrence->occurrenceId
            || $ledgerRecord->correlation->attemptId !== $capability->attemptId
            || $ledgerRecord->correlation->runId !== $capability->runId
            || $ledgerRecord->correlation->executionId !== $capability->executionId
            || !$ledgerRecord->occurrence->equals($capability->occurrence)
            || $capability->launchDescriptor === null
            || !$capability->launchDescriptor->equals($worker->request->descriptor)
            || $scope->reservation->launchPhase !== ExecutionDispatchReservation::OBSERVED
            || $ledgerRecord->launchDescriptor === null
            || !$ledgerRecord->launchDescriptor->equals($worker->request->descriptor)
            || !$scope->descriptor->rootDescriptor?->equals($worker->request->descriptor)
            || $ledgerRecord->processIdentity === null
            || $ledgerRecord->processIdentity->pid !== $rootIdentity->pid
            || $ledgerRecord->processIdentity->processGroup !== $rootIdentity->processGroupId
            || $ledgerRecord->processIdentity->session !== $rootIdentity->sessionId
            || $ledgerRecord->processIdentity->startTicks !== $rootIdentity->startTicks
            || $ledgerRecord->processIdentity->bootId !== $rootIdentity->bootId) {
            return null;
        }
        $observation = $scope->observation;
        $identity = $observation?->processIdentity;
        if ($observation === null || $identity === null || $observation->closure?->isConfirmed() === true) {
            return null;
        }
        $expected = new ExecutionProcessIdentity(
            $identity->pid,
            $identity->processGroup,
            $identity->session,
            $identity->startTicks,
            $identity->bootId,
        );
        $lookup = $this->observer->lookup($scope->descriptor, $expected);
        if (!$lookup->matched() || $lookup->handle === null || $lookup->proof?->status !== ProcessScopeProof::LIVE
            || $lookup->identity === null || !$lookup->identity->descriptor->equals($scope->descriptor)
            || $lookup->identity->pid !== $expected->pid
            || $lookup->identity->processGroupId !== $expected->processGroup
            || $lookup->identity->sessionId !== $expected->session
            || $lookup->identity->startTicks !== $expected->startTicks
            || $lookup->identity->bootId !== $expected->bootId) {
            return null;
        }
        return $lookup->handle;
    }
}
