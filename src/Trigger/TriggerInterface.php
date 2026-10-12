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
 */
interface TriggerInterface extends Stringable
{
    /**
     * Returns the next run date strictly after the given run, or null if the trigger is exhausted.
     */
    public function getNextRunDate(DateTimeImmutable $lastRun): ?DateTimeImmutable;
}
