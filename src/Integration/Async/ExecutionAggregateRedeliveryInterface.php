<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

/**
 * Atomically close the old attempt and allocate one strictly newer generation.
 *
 * Implementations must validate both fences and source identity while holding
 * the occurrence lock, merge the supplied CLOSED process facts, and publish
 * the replacement generation as one fenced transition. A caller must not use
 * separate revoke and rotate operations for aggregate redelivery.
 */
interface ExecutionAggregateRedeliveryInterface
{
    public function redeliverAfterClosureUnderLock(
        ExecutionOccurrenceKey $occurrence,
        ExecutionRecoveryFence $recoveryFence,
        ExecutionFence $newFence,
        int $nowUs,
        ExecutionFence $expectedFence,
        string $sourceIdentity,
        ExecutionLaunchObservation $rootClosure,
        ?ExecutionLaunchObservation $commandClosure,
    ): ExecutionGenerationResult;
}
