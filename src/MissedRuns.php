<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

/**
 * What a task does about its own runs that were due while nothing was there to run them.
 *
 * A tick finds every run of a task that is due and emits them oldest first. Where it finds
 * more than one, all but the newest were missed: the scheduler reached them after the next run
 * of that task had already come round. This says what becomes of those.
 *
 * One schedule holds tasks that want different answers. A metrics refresh has nothing to gain
 * from the readings it did not take, a reconciliation has to cover every window it was away
 * for, and a nightly report sent nine hours late is worse than not sent at all.
 *
 * A missed run is recorded as skipped rather than dropped: the checkpoint moves past it either
 * way, so the tick after this one starts where this one finished and no run is discovered twice.
 */
enum MissedRuns: string
{
    /**
     * None of them runs, and neither does the run they were found with: a task that fell behind
     * resumes at its next run rather than catching up at all.
     *
     * A tick that finds a single run due has missed nothing, so that run is emitted under this
     * as under any other: a task whose schedule is being kept runs on it.
     */
    case Skip = 'skip';

    /**
     * Only the newest run of the ones due runs; the rest are recorded as skipped. What the task
     * does is worth doing once, however long it was not done.
     */
    case Last = 'last';

    /**
     * Every missed run runs, oldest first, at most as many in one tick as the limit allows.
     * What a tick does not reach stays behind the checkpoint for the tick after it.
     */
    case All = 'all';
}
