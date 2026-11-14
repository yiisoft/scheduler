<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Trigger;

use DateTimeImmutable;
use Stringable;

/**
 * A trigger answers one question: given the last run, when is the next one?
 *
 * Implementations must be stateless and must return a date strictly after the given one;
 * returning null means the trigger is exhausted and won't be asked again.
 *
 * This is the whole contract: a trigger answering only this is asked one date at a time,
 * however far behind the schedule is. One whose sequence can be enumerated more cheaply than by
 * walking it answers {@see TriggerRangeInterface} as well, and is asked for a batch at a time.
 */
interface TriggerInterface extends Stringable
{
    /**
     * Returns the next run date strictly after the given run, or null if the trigger is exhausted.
     */
    public function getNextRunDate(DateTimeImmutable $lastRun): ?DateTimeImmutable;
}
