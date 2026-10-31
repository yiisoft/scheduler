<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

/**
 * What became of a run of a task, as the schedule checkpoint records it.
 */
enum TaskStatus: string
{
    /**
     * Handed out, outcome not known: a run in flight, or one left by a process that is gone. The
     * first is left alone; the second is handed out again, at once when the process can be shown
     * to be gone and otherwise once the reclaim window of the schedule runs out.
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
