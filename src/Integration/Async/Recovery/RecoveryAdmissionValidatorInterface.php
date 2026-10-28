<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Recovery;

use Yiisoft\Scheduler\Integration\Async\ExecutionLedgerRecord;

/** Validates the exact admitted source revision and command-free policy snapshot. */
interface RecoveryAdmissionValidatorInterface
{
    public function accepts(ExecutionLedgerRecord $record, string $sourceIdentity): bool;
}
