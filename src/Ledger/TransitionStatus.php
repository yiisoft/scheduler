<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

enum TransitionStatus: string
{
    /** The record was changed and the result carries it as it now stands. */
    case Accepted = 'accepted';

    /**
     * The authority named is not the one the record carries, so nothing was changed. The
     * execution has been authorised again since this caller last read it.
     */
    case StaleAuthority = 'stale-authority';

    /**
     * An outcome is already recorded, and an outcome is authoritative. Nothing was changed; a
     * later observation does not replace what the work itself reported.
     */
    case AlreadyTerminal = 'already-terminal';

    /** There is no such execution in the ledger. */
    case Missing = 'missing';
}
