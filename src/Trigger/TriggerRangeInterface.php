<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Trigger;

use DateTimeImmutable;

/** A trigger that can seek and enumerate a bounded portion of its schedule. */
interface TriggerRangeInterface extends TriggerInterface
{
    public function getNextRunDateAtOrAfter(DateTimeImmutable $date, ?DateTimeImmutable $phase = null): ?DateTimeImmutable;

    /** @return list<DateTimeImmutable> */
    public function getRunDates(
        DateTimeImmutable $after,
        DateTimeImmutable $before,
        int $limit,
        ?DateTimeImmutable $phase = null,
    ): array;
}
