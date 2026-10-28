<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

/** Durable launch handoff port implemented by ledgers supporting launch descriptors. */
interface ExecutionLaunchDescriptorLedgerInterface
{
    public function recordLaunchDescriptor(
        ExecutionOccurrenceKey $occurrence,
        ExecutionFence $expectedFence,
        ExecutionRecoveryFence $recoveryFence,
        ExecutionLaunchDescriptor $descriptor,
        string $sourceIdentity,
    ): ExecutionDispatchResult;
}
