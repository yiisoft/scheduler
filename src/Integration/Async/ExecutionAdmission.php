<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

/** Result returned only after the ledger's atomic admission operation commits. */
final class ExecutionAdmission
{
    public function __construct(public readonly ExecutionLedgerRecord $record, public readonly bool $newlyAdmitted) {}

    public function isExisting(): bool
    {
        return !$this->newlyAdmitted;
    }
}
