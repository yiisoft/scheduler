<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

/**
 * Creates application runtime only after ExecutionWorkerRuntimeComposer has accepted
 * the fenced ledger claim. Implementations must not bootstrap before this call.
 */
interface ExecutionWorkerRuntimeFactoryInterface
{
    public function create(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $claimedRecord): ExecutionWorkerRuntimeInterface;
}
