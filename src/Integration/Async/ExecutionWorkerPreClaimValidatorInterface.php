<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

/** Validates admission facts before a worker attempts to claim the ledger record. */
interface ExecutionWorkerPreClaimValidatorInterface
{
    public function validate(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $observedRecord): void;
}
