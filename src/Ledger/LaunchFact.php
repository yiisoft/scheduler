<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

/**
 * What is durably known about the attempt to start a process for an execution.
 *
 * The three are deliberately not two. Proving that nothing started is what makes another launch
 * safe; not knowing is what makes it a decision for recovery rather than a repeat. Writing the
 * second as the first is how an execution that may well be running gets launched again.
 */
enum LaunchFact: string
{
    /** Nothing was attempted yet. */
    case None = 'none';

    /**
     * The launch failed before the process began, and that is proven rather than assumed: the
     * work cannot have started, so another attempt repeats nothing.
     */
    case ProvenNotStarted = 'proven-not-started';

    /** A process was started and its identity is recorded. */
    case Started = 'started';

    /**
     * The attempt was made and its result was never learned. The process may be running, may
     * have finished, may never have begun. Recovery decides; nothing here may turn it into
     * either of the other two without evidence.
     */
    case Unknown = 'unknown';
}
