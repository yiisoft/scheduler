<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

/**
 * How far an admitted execution has got, as the ledger durably knows it.
 *
 * This is the lifecycle of the record, not what the task did: {@see ExecutionOutcome} says
 * that, and only once there is something to say. The two are separate facts because a record
 * can hold a business outcome while the process that produced it is still owned, and because a
 * process that is gone proves nothing about what the task achieved.
 */
enum ExecutionPhase: string
{
    /**
     * Durably admitted and nothing more. The occurrence is this ledger's to account for, and no
     * scheduling progress may be acknowledged past it until it is.
     */
    case Admitted = 'admitted';

    /** A planner holds the right to launch it, under the authority the record carries. */
    case Claimed = 'claimed';

    /** A launch was attempted and what came of it is recorded in the launch facts. */
    case Launched = 'launched';

    /** An outcome is recorded. The record may still hold process ownership. */
    case Terminal = 'terminal';
}
