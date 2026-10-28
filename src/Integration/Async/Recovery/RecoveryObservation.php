<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Recovery;

use InvalidArgumentException;
use Yiisoft\Scheduler\Integration\Async\{ExecutionFence, ExecutionLaunchDescriptor, ExecutionOccurrenceKey, ExecutionRecoveryFence, ExecutionProcessIdentity};
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessHandleInterface, ProcessIdentity, ProcessLookupResult, ProcessScopeProof};

use function in_array;

/** Immutable, role-bound result of observing one persisted execution scope. */
final class RecoveryObservation
{
    public const ROOT = 'root';
    public const COMMAND = 'command';

    public function __construct(
        public readonly string $role,
        public readonly ExecutionLaunchDescriptor $descriptor,
        public readonly ProcessLookupResult $lookup,
        public readonly ExecutionOccurrenceKey $occurrence,
        public readonly ExecutionFence $executionFence,
        public readonly ?ExecutionRecoveryFence $recoveryFence,
        public readonly ?ProcessHandleInterface $verifiedHandle = null,
        public readonly ?ExecutionProcessIdentity $expectedIdentity = null,
    ) {
        if (!in_array($role, [self::ROOT, self::COMMAND], true)) {
            throw new InvalidArgumentException('Recovery observation role is invalid.');
        }
        $expectedRole = $role === self::ROOT ? ExecutionLaunchDescriptor::ROLE_WORKER : ExecutionLaunchDescriptor::ROLE_COMMAND;
        if ($descriptor->role !== $expectedRole || $descriptor->occurrence === null || !$descriptor->occurrence->equals($occurrence)
            || $descriptor->generation !== $executionFence->generation) {
            throw new InvalidArgumentException('Recovery observation descriptor binding is invalid.');
        }
        if ($role === self::COMMAND && ($descriptor->rootDescriptor === null || !$descriptor->rootDescriptor->occurrence?->equals($occurrence))) {
            throw new InvalidArgumentException('Recovery command observation root binding is invalid.');
        }
        if ($lookup->proof !== null) {
            if (!$lookup->proof->descriptor->equals($descriptor)
                || ($lookup->proof->identity !== null && $expectedIdentity !== null && !$lookup->proof->identity->sameFacts(new ProcessIdentity(
                    $expectedIdentity->pid,
                    $expectedIdentity->processGroup,
                    $expectedIdentity->session,
                    $expectedIdentity->startTicks,
                    $expectedIdentity->bootId,
                    $descriptor,
                )))) {
                throw new InvalidArgumentException('A recovery observation proof is not bound to the persisted process facts.');
            }
        }
        if ($lookup->matched()) {
            if ($lookup->identity === null || !$lookup->identity->descriptor->equals($descriptor)
                || ($expectedIdentity !== null && !$lookup->identity->sameFacts(new ProcessIdentity(
                    $expectedIdentity->pid,
                    $expectedIdentity->processGroup,
                    $expectedIdentity->session,
                    $expectedIdentity->startTicks,
                    $expectedIdentity->bootId,
                    $descriptor,
                )))
                || $verifiedHandle !== $lookup->handle) {
                throw new InvalidArgumentException('A matching recovery observation is not bound to the persisted process facts.');
            }
        } elseif ($verifiedHandle !== null) {
            throw new InvalidArgumentException('A verified recovery handle must match its lookup result.');
        }
    }

    public function isLive(): bool
    {
        // A MATCH carries process facts, but is not a verified live observation
        // until the observer also supplies the bound live scope proof.
        return $this->lookup->matched() && $this->lookup->proof?->status === ProcessScopeProof::LIVE;
    }

    public function isUncertain(): bool
    {
        return !$this->isLive() && !$this->lookup->completedNoMatch() && $this->lookup->status !== ProcessLookupResult::CLOSED;
    }
}
