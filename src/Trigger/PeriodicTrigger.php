<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Trigger;

use DateInterval;
use DateTimeImmutable;
use Yiisoft\Scheduler\Exception\LogicException;

use function abs;
use function intdiv;
use function is_int;
use function round;
use function rtrim;
use function sprintf;

use const PHP_INT_MAX;

/**
 * A trigger firing at a fixed interval, anchored to a "from" date (by default the midnight of
 * January 1, 2001 in the default timezone).
 *
 * The run dates are the multiples of the interval counted from the anchor, so the sequence
 * never drifts: `from`, `from + 1 × interval`, `from + 2 × interval`, and so on. With the
 * default anchor that means "every 10 minutes" fires at :00, :10, :20 and so on, no matter when
 * the process evaluating the trigger was started or what date it is asked about.
 *
 * An interval of hours, minutes and seconds only is an amount of time that passes: a change of
 * the clock neither skips nor repeats its runs. One with days, months or years in it follows
 * the calendar of the anchor's timezone instead, so "every day" stays at the same local time.
 */
final class PeriodicTrigger implements TriggerInterface
{
    private const NON_ADVANCING_MESSAGE
        = 'The interval does not advance the date; use a cron expression for calendar-based schedules.';

    /**
     * The highest multiple of the interval the anchored search will consider, so the probe cannot
     * overflow. Reaching it means the interval is too small to ever span the distance asked for.
     */
    private const MAX_MULTIPLE = PHP_INT_MAX >> 2;

    /**
     * The anchor used when none is given. A fixed one keeps the trigger a pure function of the
     * date it is asked about: anchoring to that date instead would make the next run depend on
     * when the schedule was last evaluated, so a process started anew for every tick, as a
     * `schedule:run` cron entry is, would push the run one interval ahead of itself forever.
     *
     * This one is a Monday and the first day of a year, so weeks are counted from Mondays and
     * months from the first; and it is recent enough for the timezone to be at the offset it
     * has today, which it was not necessarily in 1970.
     */
    private const DEFAULT_FROM = '2001-01-01 00:00:00';

    /**
     * Dates an interval is tried on to tell whether it is a plain amount of time: the middle
     * and the end of a month, a weekday and a weekend, so a relative format shows itself on
     * at least one of them.
     */
    private const SCALABILITY_PROBES = ['2001-01-17 12:34:56 UTC', '2001-03-31 00:00:00 UTC'];

    private readonly ?int $seconds;
    private readonly ?DateInterval $dateInterval;
    /**
     * The interval in seconds when it is a plain amount of them, whichever way it was given.
     * Such an interval is counted on the timestamp rather than on the wall clock.
     */
    private readonly ?int $step;
    private readonly DateTimeImmutable $from;

    /**
     * @param int|DateInterval $interval The interval between runs, in seconds or as a date interval.
     * @param DateTimeImmutable|null $from The anchor date. Defaults to the midnight of January 1, 2001
     * in the default timezone.
     * @param DateTimeImmutable|null $until The date after which the trigger is exhausted.
     */
    public function __construct(
        int|DateInterval $interval,
        ?DateTimeImmutable $from = null,
        private readonly ?DateTimeImmutable $until = null,
    ) {
        $this->from = $from ?? new DateTimeImmutable(self::DEFAULT_FROM);

        if (is_int($interval)) {
            if ($interval <= 0) {
                throw new LogicException(sprintf('The interval must be greater than zero, "%d" given.', $interval));
            }

            $this->seconds = $interval;
            $this->dateInterval = null;
            $this->step = $interval;
        } else {
            $this->seconds = null;
            $this->dateInterval = $interval;

            $this->assertScalable();

            $this->step = self::wholeSeconds($interval);
        }
    }

    public function __toString(): string
    {
        if (null !== $this->seconds) {
            return sprintf('every(%d seconds)', $this->seconds);
        }

        /** @var DateInterval $interval */
        $interval = $this->dateInterval;

        return sprintf('every(%s)', self::describe($interval));
    }

    public function getNextRunDate(DateTimeImmutable $lastRun): ?DateTimeImmutable
    {
        if (null !== $this->until && $lastRun >= $this->until) {
            return null;
        }

        if ($lastRun < $this->from) {
            $next = $this->from;
        } elseif (null !== $this->step) {
            $elapsed = $lastRun->getTimestamp() - $this->from->getTimestamp();
            $next = $this->from->getTimestamp() + intdiv($elapsed, $this->step) * $this->step;
            $next = (new DateTimeImmutable())->setTimestamp($next)->setTimezone($lastRun->getTimezone());

            if ($next <= $lastRun) {
                $next = $next->setTimestamp($next->getTimestamp() + $this->step);
            }
        } else {
            $next = $this->firstMultipleAfter($this->from, $lastRun);
        }

        if (null !== $this->until && $next > $this->until) {
            return null;
        }

        return $next;
    }

    /**
     * Returns the first multiple of the interval counted from the anchor that is strictly after the given date.
     *
     * The multiple is found by an exponential probe followed by a binary search, so the cost is
     * logarithmic in the distance from the anchor instead of linear in the number of intervals.
     */
    private function firstMultipleAfter(DateTimeImmutable $from, DateTimeImmutable $lastRun): DateTimeImmutable
    {
        $first = $this->multiple($from, 1);

        if ($first <= $from) {
            throw new LogicException(self::NON_ADVANCING_MESSAGE);
        }

        if ($first > $lastRun) {
            return $first;
        }

        // Lower bound is known to be at or before $lastRun; find an upper bound past it.
        $low = 1;
        $high = 2;

        while ($this->multiple($from, $high) <= $lastRun) {
            $low = $high;

            if ($high > self::MAX_MULTIPLE) {
                throw new LogicException(
                    'The interval is too small to reach the requested date from the anchor.',
                );
            }

            $high *= 2;
        }

        while ($high - $low > 1) {
            $mid = intdiv($low + $high, 2);

            if ($this->multiple($from, $mid) <= $lastRun) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return $this->multiple($from, $high);
    }

    /**
     * The length of an interval of hours, minutes and whole seconds only, null for any other.
     *
     * Multiples of such an interval are better counted on the timestamp: added to a date as
     * hours and minutes they follow the wall clock, which skips the hour repeated when the
     * clocks go back, and every run due in it.
     */
    private static function wholeSeconds(DateInterval $interval): ?int
    {
        if (
            0 !== $interval->y
            || 0 !== $interval->m
            || 0 !== $interval->d
            || 1 === $interval->invert
            || 0 !== (int) round($interval->f * 1_000_000)
        ) {
            return null;
        }

        $seconds = $interval->h * 3600 + $interval->i * 60 + $interval->s;

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * The run dates are multiples of the interval, computed from its years, months, days and
     * time. An interval made from a relative format such as "1 weekday" or "first monday of
     * next month" carries what it means outside of those, so its multiples would be dates it
     * never leads to; it is refused here, where the task is declared, rather than left to
     * run at the wrong time or not at all.
     */
    private function assertScalable(): void
    {
        /** @var DateInterval $interval */
        $interval = $this->dateInterval;

        foreach (self::SCALABILITY_PROBES as $probe) {
            $date = new DateTimeImmutable($probe);

            if ($date->add($interval) != $this->multiple($date, 1)) {
                throw new LogicException(
                    'The interval is not a fixed amount of years, months, days and time;'
                    . ' use a cron expression for calendar-based schedules.',
                );
            }
        }
    }

    /**
     * Applies the interval the given number of times at once, in a single step.
     */
    private function multiple(DateTimeImmutable $from, int $times): DateTimeImmutable
    {
        /** @var DateInterval $interval */
        $interval = $this->dateInterval;

        $factor = 1 === $interval->invert ? -$times : $times;

        // Whole seconds are carried out of the microseconds: counted from an anchor decades
        // away, a sub-second interval adds up to more of them than the format accepts.
        $microseconds = $factor * (int) round($interval->f * 1_000_000);

        $scaled = DateInterval::createFromDateString(sprintf(
            '%d years %d months %d days %d hours %d minutes %d seconds %d microseconds',
            $factor * $interval->y,
            $factor * $interval->m,
            $factor * $interval->d,
            $factor * $interval->h,
            $factor * $interval->i,
            $factor * $interval->s + intdiv($microseconds, 1_000_000),
            $microseconds % 1_000_000,
        ));

        // PHP 8.1 and 8.2 report a malformed spec with false instead of throwing; the spec is
        // built here from integers, so this only guards against an impossible state.
        if (!$scaled instanceof DateInterval) {
            throw new LogicException(sprintf('The interval could not be scaled by %d.', $times));
        }

        return $from->add($scaled);
    }

    /**
     * Renders the interval as an ISO 8601 duration, omitting the zero components.
     *
     * Every non-zero component is kept, so two intervals of different length never render the same.
     */
    private static function describe(DateInterval $interval): string
    {
        $date = '';
        $date .= 0 === $interval->y ? '' : $interval->y . 'Y';
        $date .= 0 === $interval->m ? '' : $interval->m . 'M';
        $date .= 0 === $interval->d ? '' : $interval->d . 'D';

        $time = '';
        $time .= 0 === $interval->h ? '' : $interval->h . 'H';
        $time .= 0 === $interval->i ? '' : $interval->i . 'M';

        $seconds = self::describeSeconds($interval);
        $time .= '' === $seconds ? '' : $seconds . 'S';

        if ('' === $date && '' === $time) {
            return 'P0D';
        }

        return (1 === $interval->invert ? '-P' : 'P') . $date . ('' === $time ? '' : 'T' . $time);
    }

    private static function describeSeconds(DateInterval $interval): string
    {
        $microseconds = (int) round($interval->f * 1_000_000);

        if (0 === $interval->s && 0 === $microseconds) {
            return '';
        }

        if (0 === $microseconds) {
            return (string) $interval->s;
        }

        return rtrim(rtrim(sprintf('%d.%06d', $interval->s, abs($microseconds)), '0'), '.');
    }
}
