<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Generator;

/**
 * What came of asking to plan a schedule.
 *
 * @internal
 */
enum OwnershipStatus
{
    /** The schedule is this process's to plan. */
    case Held;

    /** Another process is planning this schedule, and it is still there. */
    case Taken;

    /**
     * The schedule this process acquired is no longer the schedule it holds: its tasks have
     * changed under it, or another process has taken it over with tasks of its own. Nothing
     * may be planned or admitted under what this process worked out before that.
     */
    case Fenced;
}
