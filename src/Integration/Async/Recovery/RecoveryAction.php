<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Recovery;

use InvalidArgumentException;
use Yiisoft\Scheduler\Integration\Async\{ExecutionFence, ExecutionLedgerRecord, ExecutionProcessIdentity, ExecutionRecoveryFence};
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessHandleInterface, ProcessLookupResult, ProcessScopeProof};

use function in_array;

/** Explicit, side-effect-free handoff from recovery classification to its owner. */
final class RecoveryAction
{
    public const TERMINAL = 'terminal/no-op';
    public const RELAUNCH = 'safe-same-generation-relaunch';
    public const AGGREGATE_REDELIVERY = 'aggregate/full-scope-redelivery';
    public const BLOCKED = 'blocked/unresolved';
    public const WAIT = 'unresolved/wait-observe';
    public const EXPIRED = 'expired/observe-live';
    public const EXPIRED_UNRESOLVED = 'expired/unresolved';

    /** @var list<RecoveryObservation> */
    public readonly array $observations;

    /** @param list<RecoveryObservation> $observations */
    public function __construct(
        public readonly string $kind,
        public readonly ExecutionLedgerRecord $record,
        public readonly ExecutionFence $expectedFence,
        public readonly ?ExecutionRecoveryFence $recoveryFence,
        public readonly ?ProcessLookupResult $lookup = null,
        public readonly ?int $deadlineUs = null,
        public readonly ?string $reason = null,
        /** Verified handle handed to the planner for later supervision. */
        public readonly ?ProcessHandleInterface $attachedHandle = null,
        /** @var list<RecoveryObservation> Role-scoped observations for this record. */
        array $observations = [],
    ) {
        if (!in_array($kind, [self::TERMINAL, self::RELAUNCH, self::AGGREGATE_REDELIVERY, self::BLOCKED, self::WAIT, self::EXPIRED, self::EXPIRED_UNRESOLVED], true)) {
            throw new InvalidArgumentException('Invalid recovery action.');
        }
        $seen = [];
        $root = null;
        foreach ($observations as $observation) {
            if (!$observation instanceof RecoveryObservation) {
                throw new InvalidArgumentException('Recovery action observations are invalid.');
            }
            if (!$observation->occurrence->equals($record->occurrence)
                || !$observation->executionFence->equals($expectedFence)
                || (($observation->recoveryFence === null) !== ($recoveryFence === null))) {
                throw new InvalidArgumentException('Recovery observation is not bound to the action record.');
            }
            if ($observation->recoveryFence !== null && ($recoveryFence === null || !$observation->recoveryFence->equals($recoveryFence))) {
                throw new InvalidArgumentException('Recovery observation is not bound to the action recovery fence.');
            }
            $expectedDescriptor = match ($observation->role) {
                RecoveryObservation::ROOT => $record->launchDescriptor,
                RecoveryObservation::COMMAND => $record->commandScope?->descriptor,
                default => null,
            };
            $persistedIdentity = $observation->role === RecoveryObservation::ROOT
                ? $record->processIdentity
                : $record->commandScope?->observation?->processIdentity;
            if ($expectedDescriptor === null || !$observation->descriptor->equals($expectedDescriptor)
                || !$this->samePersistedIdentity($observation->expectedIdentity, $persistedIdentity)) {
                throw new InvalidArgumentException('Recovery observation facts are not persisted on the record.');
            }
            $key = $observation->role . ':' . $observation->descriptor->marker();
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Duplicate recovery observations are not allowed.');
            }
            $seen[$key] = true;
            if ($observation->role === RecoveryObservation::ROOT) {
                $root = $observation;
            }
        }
        $this->observations = $observations;
        // Keep the pre-Stage-7 fields as root aliases. A command-only live
        // scope is deliberately represented by observations, not fabricated aliases.
        if ($root !== null) {
            if ($lookup !== $root->lookup || $attachedHandle !== $root->verifiedHandle) {
                throw new InvalidArgumentException('Recovery root aliases are inconsistent.');
            }
        } elseif ($lookup !== null || $attachedHandle !== null) {
            throw new InvalidArgumentException('Command-only recovery actions cannot have root aliases.');
        }
        if ($kind === self::WAIT) {
            $live = false;
            foreach ($observations as $observation) {
                if ($observation->isLive() && $observation->verifiedHandle !== null) {
                    $live = true;
                    break;
                }
            }
            if (!$live && !($observations === []
                && $lookup?->matched() === true
                && $lookup->proof?->status === ProcessScopeProof::LIVE
                && $attachedHandle !== null)) {
                throw new InvalidArgumentException('Waiting for live observation requires a matching lookup and attached process handle.');
            }
        }
        $recordRecoveryFence = $record->recoveryFence;
        if (!$record->fence->equals($expectedFence)
            || ($recordRecoveryFence === null) !== ($recoveryFence === null)
            || ($recordRecoveryFence !== null && ($recoveryFence === null || !$recordRecoveryFence->equals($recoveryFence)))) {
            throw new InvalidArgumentException('Recovery action is not fenced by the record.');
        }
    }

    private function samePersistedIdentity(?ExecutionProcessIdentity $supplied, ?ExecutionProcessIdentity $persisted): bool
    {
        if ($supplied === null || $persisted === null) {
            return $supplied === null && $persisted === null;
        }
        return $supplied->pid === $persisted->pid
            && $supplied->processGroup === $persisted->processGroup
            && $supplied->session === $persisted->session
            && $supplied->startTicks === $persisted->startTicks
            && $supplied->bootId === $persisted->bootId;
    }
}
