<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

/** Read-only queries for durable execution indexes. */
interface ExecutionLedgerQueryInterface
{
    /** Operational upper bound for one recovery page, keeping each query bounded. */
    public const MAX_RECOVERY_PAGE_SIZE = 256;

    public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord;

    /** Whether any admitted execution snapshot exists for this task. */
    public function hasTaskHistory(string $taskKey): bool;

    /** @return list<ExecutionLedgerRecord> Active, non-terminal executions. */
    public function activeExecutions(string $sourceIdentity): array;

    /** @return list<ExecutionLedgerRecord> Records still holding source ownership. */
    public function ownershipHoldingExecutions(string $sourceIdentity): array;

    /**
     * Returns a deterministic, source-scoped page over active or ownership-held records.
     *
     * @param non-empty-string|null $cursor Opaque cursor returned by a previous page.
     * @param int $limit
     */
    public function recoveryPage(string $sourceIdentity, ?string $cursor, int $limit): ExecutionLedgerRecoveryPage;
}
