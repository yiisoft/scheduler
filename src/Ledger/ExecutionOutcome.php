<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

/**
 * What became of the work, once it is known.
 *
 * A business outcome, which is why a process exiting is not one of these: an exit code is an
 * observation about a process and is recorded as such. Once written, an outcome is authoritative
 * and a later observation cannot replace it.
 */
enum ExecutionOutcome: string
{
    case Done = 'done';
    case Failed = 'failed';

    /** The occurrence was deliberately not run, by a policy decision rather than a failure. */
    case Skipped = 'skipped';

    /** Admitted work that was given up before it produced anything. */
    case Cancelled = 'cancelled';
}
