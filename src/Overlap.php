<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

/**
 * What a task does when a run of it is due while an earlier one is still going.
 *
 * The question is asked of the ledger rather than of what this process happens to remember, so
 * a run owned by a planner that was restarted, or by a planner on another host, counts as one
 * still going. A schedule without a ledger can only answer from the children this process
 * started, which is what it always answered from.
 */
enum Overlap: string
{
    /**
     * Start it anyway. The task can have as many of its runs going at once as the capacity of
     * its source allows, which is what a task doing independent work wants: two imports of
     * different files do not wait for each other.
     */
    case Allow = 'allow';

    /**
     * Leave it. The run is recorded as skipped and the schedule moves past it, so a task taking
     * longer than its own interval does not pile up a process per due time and never catch up.
     */
    case Skip = 'skip';
}
