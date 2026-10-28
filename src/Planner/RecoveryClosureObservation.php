<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use InvalidArgumentException;
use Yiisoft\Scheduler\Integration\Async\{ExecutionFence, ExecutionLaunchDescriptor, ExecutionLaunchObservation, ExecutionOccurrenceKey, ExecutionRecoveryFence, ExecutionStartObservation, ExecutionWorkerLaunchHandle, ExecutionProcessIdentity};
use Yiisoft\Scheduler\Integration\Async\Recovery\RecoveryObservation;

use function in_array;

/** Role-bound terminal facts for a recovered process scope. */
final class RecoveryClosureObservation
{
    public function __construct(
        public readonly string $role,
        public readonly ExecutionOccurrenceKey $occurrence,
        public readonly ExecutionLaunchDescriptor $descriptor,
        public readonly ?ExecutionLaunchDescriptor $rootDescriptor,
        public readonly ExecutionFence $executionFence,
        public readonly ?ExecutionRecoveryFence $recoveryFence,
        public readonly ExecutionProcessIdentity $processIdentity,
        public readonly ?ExecutionStartObservation $startObservation,
        public readonly ExecutionWorkerLaunchHandle $retainedHandle,
        public readonly ExecutionLaunchObservation $closure,
    ) {
        if (!in_array($role, [RecoveryObservation::ROOT, RecoveryObservation::COMMAND], true)) {
            throw new InvalidArgumentException('Recovery closure role is invalid.');
        }
        $expectedRole = $role === RecoveryObservation::ROOT ? ExecutionLaunchDescriptor::ROLE_WORKER : ExecutionLaunchDescriptor::ROLE_COMMAND;
        if ($descriptor->role !== $expectedRole || $descriptor->occurrence === null || !$descriptor->occurrence->equals($occurrence)
            || $descriptor->generation !== $executionFence->generation) {
            throw new InvalidArgumentException('Recovery closure descriptor binding is invalid.');
        }
        if ($role === RecoveryObservation::COMMAND
            && ($rootDescriptor === null || $descriptor->rootDescriptor === null || !$rootDescriptor->equals($descriptor->rootDescriptor)
                || $rootDescriptor->role !== ExecutionLaunchDescriptor::ROLE_WORKER
                || $rootDescriptor->occurrence === null || !$rootDescriptor->occurrence->equals($occurrence))) {
            throw new InvalidArgumentException('Recovery command closure root binding is invalid.');
        }
        if ($role === RecoveryObservation::ROOT && $rootDescriptor !== null) {
            throw new InvalidArgumentException('Recovery root closure cannot have a root binding.');
        }
        if ($recoveryFence === null || $recoveryFence->generation !== $executionFence->generation) {
            throw new InvalidArgumentException('Recovery closure fence is missing or invalid.');
        }
        $closureIdentity = $closure->processIdentity;
        if (!$closure->closure?->isConfirmed() || $closure->launchHandle !== $retainedHandle || $closureIdentity === null
            || $closure->pid !== $processIdentity->pid
            || $closureIdentity->pid !== $processIdentity->pid
            || $closureIdentity->processGroup !== $processIdentity->processGroup
            || $closureIdentity->session !== $processIdentity->session
            || $closureIdentity->startTicks !== $processIdentity->startTicks
            || $closureIdentity->bootId !== $processIdentity->bootId) {
            throw new InvalidArgumentException('Recovery closure process facts are invalid.');
        }
        if ($startObservation !== null && $closure->startObservation !== $startObservation
            && ($closure->startObservation === null
                || $closure->startObservation->wallMicroseconds !== $startObservation->wallMicroseconds
                || $closure->startObservation->monotonicNanoseconds !== $startObservation->monotonicNanoseconds)) {
            throw new InvalidArgumentException('Recovery closure start facts are inconsistent.');
        }
        if ($startObservation === null && $closure->startObservation !== null) {
            throw new InvalidArgumentException('Recovery closure contains unexpected start facts.');
        }
    }

    public function observation(): ExecutionLaunchObservation
    {
        return $this->closure;
    }

    public function launchObservation(): ExecutionLaunchObservation
    {
        return $this->closure;
    }
}
