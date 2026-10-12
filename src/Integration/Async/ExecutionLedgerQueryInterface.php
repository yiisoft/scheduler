<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

/** Read-only queries for durable execution indexes. */
interface ExecutionLedgerQueryInterface
{
    /** @return list<ExecutionLedgerRecord> Active, non-terminal executions. */
    public function activeExecutions(): array;

    /** @return list<ExecutionLedgerRecord> Records still holding source ownership. */
    public function ownershipHoldingExecutions(): array;

    /** Rebuilds lookup aids from authoritative retained snapshots. */
    public function repairIndexes(): void;
}
