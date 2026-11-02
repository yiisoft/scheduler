<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Trigger;

use DateTimeImmutable;

/**
 * A trigger that can also be asked for a bounded part of its sequence, rather than for one run
 * date at a time.
 *
 * A task that was due every minute through a thirty-day outage has some forty thousand runs
 * behind it. Asked for them one at a time, a schedule spends the tick walking them; asked for a
 * hundred of them, it can work through the backlog a batch per tick and keep the rest of the
 * tasks moving. The answers are the same either way: a range is a different question about the
 * same sequence, not a different sequence.
 *
 * A trigger that only knows the next run date implements {@see TriggerInterface} alone; what a
 * schedule does with one is its own decision.
 */
interface TriggerRangeInterface extends TriggerInterface
{
    /**
     * The first run date at or after the given date, as opposed to {@see getNextRunDate()}, which
     * answers with the first one strictly after it.
     *
     * @param DateTimeImmutable $date The date to seek from, itself a candidate.
     * @param DateTimeImmutable|null $phase A run already accounted for. A date at or before it is
     * never an answer, so a caller that was interrupted can ask again from where it left off and
     * receive the run after the last one it saw rather than that one again.
     *
     * @return DateTimeImmutable|null The run date, or null when the trigger is exhausted.
     */
    public function getNextRunDateAtOrAfter(DateTimeImmutable $date, ?DateTimeImmutable $phase = null): ?DateTimeImmutable;

    /**
     * The run dates falling between two dates, at most as many as asked for.
     *
     * Both bounds are exclusive: a run at `$after` belongs to the batch before this one, and a
     * run at `$before` to the batch after it, so consecutive calls neither skip nor repeat a run
     * when each one starts where the last left off.
     *
     * @param DateTimeImmutable $after The date the batch starts after.
     * @param DateTimeImmutable $before The date the batch stops before.
     * @param int $limit How many dates at most. Must be greater than zero.
     * @param DateTimeImmutable|null $phase A run already accounted for, as above.
     *
     * @return list<DateTimeImmutable> The run dates in order, empty when there are none in
     * range or the trigger is exhausted. Fewer than `$limit` of them means the range held no
     * more; exactly `$limit` says nothing about what is left, so the caller asks again from the
     * last of them.
     */
    public function getRunDates(
        DateTimeImmutable $after,
        DateTimeImmutable $before,
        int $limit,
        ?DateTimeImmutable $phase = null,
    ): array;
}
