<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

/** A bounded result from the source-scoped recovery index. */
final class ExecutionLedgerRecoveryPage
{
    /**
     * @param list<ExecutionLedgerRecord> $records
     */
    public function __construct(
        public readonly array $records,
        public readonly ?string $nextCursor,
    ) {}
}
