<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

/**
 * Reading the durable facts of an execution.
 *
 * Separate from writing them because the two have different callers: a health probe and a
 * `schedule:list` read the ledger without any right to change it, and an application supplying
 * its own storage may well answer the two from different places.
 *
 * Every query is scoped to one source and bounded by a limit, so that a ledger holding a year
 * of executions can still be walked by a planner that has a tick to finish. The order is fixed
 * rather than unspecified, so that a caller continuing from where it left off sees every record
 * once.
 */
interface LedgerQueryInterface
{
    /** The record for one occurrence, or null when it was never admitted. */
    public function find(ExecutionIdentity $identity): ?ExecutionRecord;

    /**
     * The executions of a source that recovery still has something to decide about: everything
     * without an outcome, and everything still holding a process.
     *
     * Ordered by the occurrence they are for, oldest first, so a caller works through a backlog
     * in the order it happened. `$after` continues a previous page from its last record.
     *
     * @param positive-int $limit
     *
     * @return list<ExecutionRecord>
     */
    public function unresolved(string $source, int $limit, ?ExecutionIdentity $after = null): array;

    /**
     * The executions of a source still holding a process, whatever their outcome.
     *
     * This is what capacity is reckoned from, and it is deliberately not the same question as
     * the one above: an execution that reported success an hour ago and whose process has not
     * been proven closed still occupies a slot.
     *
     * @param positive-int $limit
     *
     * @return list<ExecutionRecord>
     */
    public function holdingProcess(string $source, int $limit, ?ExecutionIdentity $after = null): array;
}
