<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

/**
 * What became of a run of a task, as the schedule checkpoint records it.
 */
enum TaskStatus: string
{
    /**
     * Handed out, outcome not known: a run in flight, or one left by a process that is gone.
     *
     * Every claim is handed out again once the reclaim window of the schedule runs out, including
     * one whose process is still there; a claim of a process of this host that can be shown to be
     * gone is handed out again before that.
     */
    case Claimed = 'claimed';

    /** The run was made and the task reported no error. */
    case Done = 'done';

    /** The run was made and the task failed. */
    case Failed = 'failed';

    /** Not made: a previous run was still going, or a newer missed run superseded it. */
    case Skipped = 'skipped';

    /** Claimed and handed back unstarted, so the run is due again. */
    case Released = 'released';

    /** The run was not made: a listener cancelled it. */
    case Cancelled = 'cancelled';
}
