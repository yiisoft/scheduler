<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Trigger;

use DateInterval;
use DateTimeImmutable;
use Yiisoft\Schedule\Exception\LogicException;
use InvalidArgumentException;

use function abs;
use function intdiv;
use function is_int;
use function round;
use function rtrim;
use function sprintf;

use const PHP_INT_MAX;

/**
 * A trigger firing at a fixed interval, anchored to a "from" date (by default the first time it is asked).
 *
 * When anchored, the run dates are the multiples of the interval counted from the anchor, so the
 * sequence never drifts: `from`, `from + 1 × interval`, `from + 2 × interval`, and so on.
 */
final class PeriodicTrigger implements TriggerRangeInterface
{
    private const NON_ADVANCING_MESSAGE
        = 'The interval does not advance the date; use a cron expression for calendar-based schedules.';

    /**
     * The highest multiple of the interval the anchored search will consider, so the probe cannot
     * overflow. Reaching it means the interval is too small to ever span the distance asked for.
     */
    private const MAX_MULTIPLE = PHP_INT_MAX >> 2;

    private readonly ?int $seconds;
    private readonly ?DateInterval $dateInterval;

    /**
     * @param int|DateInterval $interval The interval between runs, in seconds or as a date interval.
     * @param DateTimeImmutable|null $from The anchor date. Defaults to the first time the trigger is asked.
     * @param DateTimeImmutable|null $until The date after which the trigger is exhausted.
     */
    public function __construct(
        int|DateInterval $interval,
        private readonly ?DateTimeImmutable $from = null,
        private readonly ?DateTimeImmutable $until = null,
    ) {
        if (is_int($interval)) {
            if ($interval <= 0) {
                throw new LogicException(sprintf('The interval must be greater than zero, "%d" given.', $interval));
            }

            $this->seconds = $interval;
            $this->dateInterval = null;
        } else {
            $this->seconds = null;
            $this->dateInterval = $interval;
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

    public function getNextRunDateAtOrAfter(DateTimeImmutable $date, ?DateTimeImmutable $phase = null): ?DateTimeImmutable
    {
        $anchor = $this->from ?? $phase ?? $date;
        $next = $this->atOrAfter($anchor, $date);

        // A phase is an acknowledged cursor: an occurrence at the cursor must not be returned again.
        // DateTimeImmutable comparisons include microseconds, unlike timestamp-only comparisons.
        if (null !== $phase && $next <= $phase) {
            $next = $this->atOrAfter($anchor, $phase);
            if ($next <= $phase) {
                $next = $this->add($next);
            }
        }

        return null !== $this->until && $next > $this->until ? null : $next;
    }

    public function getRunDates(
        DateTimeImmutable $after,
        DateTimeImmutable $before,
        int $limit,
        ?DateTimeImmutable $phase = null,
    ): array {
        if ($limit <= 0) {
            throw new InvalidArgumentException('The limit must be greater than zero.');
        }
        if ($before <= $after) {
            return [];
        }

        $anchor = $this->from ?? $phase ?? $after;
        if (null !== $this->seconds) {
            return $this->fixedRange($after, $before, $limit, $anchor);
        }

        return $this->calendarRange($after, $before, $limit, $anchor);
    }

    public function getNextRunDate(DateTimeImmutable $lastRun): ?DateTimeImmutable
    {
        if (null !== $this->until && $lastRun >= $this->until) {
            return null;
        }

        if (null === $this->from) {
            $next = $this->add($lastRun);

            if ($next <= $lastRun) {
                throw new LogicException(self::NON_ADVANCING_MESSAGE);
            }
        } elseif ($lastRun < $this->from) {
            $next = $this->from;
        } elseif (null !== $this->seconds) {
            $elapsed = $lastRun->getTimestamp() - $this->from->getTimestamp();
            $next = $this->atTimestamp($this->from, $this->from->getTimestamp() + intdiv($elapsed, $this->seconds) * $this->seconds)
                ->setTimezone($lastRun->getTimezone());

            if ($next <= $lastRun) {
                $next = $this->atTimestamp($next, $next->getTimestamp() + $this->seconds);
            }
        } else {
            $next = $this->firstMultipleAfter($this->from, $lastRun);
        }

        if (null !== $this->until && $next > $this->until) {
            return null;
        }

        return $next;
    }

    private function atOrAfter(DateTimeImmutable $anchor, DateTimeImmutable $date): DateTimeImmutable
    {
        if ($date <= $anchor) {
            return $anchor;
        }
        if (null !== $this->seconds) {
            $elapsed = $date->getTimestamp() - $anchor->getTimestamp();
            $steps = intdiv($elapsed, $this->seconds);
            $candidate = $this->atTimestamp($anchor, $anchor->getTimestamp() + $steps * $this->seconds);
            return $candidate >= $date ? $candidate : $this->atTimestamp($candidate, $candidate->getTimestamp() + $this->seconds);
        }

        return $this->firstMultipleAfter($anchor, $date->sub($this->microsecondInterval()));
    }

    private function microsecondInterval(): DateInterval
    {
        $interval = DateInterval::createFromDateString('1 microsecond');
        if ($interval === false) {
            throw new LogicException('Unable to create the microsecond interval.');
        }
        return $interval;
    }

    /** @return list<DateTimeImmutable> */
    private function calendarRange(DateTimeImmutable $after, DateTimeImmutable $before, int $limit, DateTimeImmutable $anchor): array
    {
        $first = $this->multiple($anchor, 1);
        if ($first <= $anchor) {
            throw new LogicException(self::NON_ADVANCING_MESSAGE);
        }
        $upper = $before;
        if (null !== $this->until && $this->until < $upper) {
            $upper = $this->until->add($this->microsecondInterval());
        }
        if ($anchor >= $upper) {
            return [];
        }

        $low = 0;
        $high = 1;
        while ($this->multiple($anchor, $high) < $upper) {
            $low = $high;
            if ($high > self::MAX_MULTIPLE) {
                throw new LogicException('The interval is too small to reach the requested date from the anchor.');
            }
            $high *= 2;
        }
        while ($high - $low > 1) {
            $mid = intdiv($low + $high, 2);
            if ($this->multiple($anchor, $mid) < $upper) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }
        $end = $low;
        $low = 0;
        $high = 1;
        while ($this->multiple($anchor, $high) <= $after) {
            $low = $high;
            $high *= 2;
            if ($high > self::MAX_MULTIPLE) {
                return [];
            }
        }
        while ($high - $low > 1) {
            $mid = intdiv($low + $high, 2);
            if ($this->multiple($anchor, $mid) <= $after) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }
        $start = $anchor > $after ? 0 : max(1, $high);
        $start = max($start, $end - $limit + 1);
        $result = [];
        for ($index = $start; $index <= $end; ++$index) {
            $date = $this->multiple($anchor, $index);
            if (null !== $this->until && $date > $this->until) {
                break;
            }
            $result[] = $date;
        }
        return $result;
    }

    /** @return list<DateTimeImmutable> */
    private function fixedRange(DateTimeImmutable $after, DateTimeImmutable $before, int $limit, DateTimeImmutable $anchor): array
    {
        $seconds = $this->seconds;
        if (null === $seconds) {
            throw new LogicException('A fixed range requires a seconds interval.');
        }
        $startIndex = $after < $anchor
            ? 0
            : intdiv($after->getTimestamp() - $anchor->getTimestamp(), $seconds) + 1;
        if ($before <= $anchor) {
            $endIndex = -1;
        } else {
            $endIndex = intdiv($before->getTimestamp() - $anchor->getTimestamp(), $seconds);
            while ($endIndex >= 0 && $this->atTimestamp($anchor, $anchor->getTimestamp() + $endIndex * $seconds) >= $before) {
                --$endIndex;
            }
        }
        if (null !== $this->until && $this->until < $before) {
            if ($this->until < $anchor) {
                $endIndex = -1;
            } else {
                $untilIndex = intdiv($this->until->getTimestamp() - $anchor->getTimestamp(), $seconds);
                while ($untilIndex >= 0 && $this->atTimestamp($anchor, $anchor->getTimestamp() + $untilIndex * $seconds) > $this->until) {
                    --$untilIndex;
                }
                $endIndex = min($endIndex, $untilIndex);
            }
        }
        if ($endIndex < $startIndex || $endIndex < 0) {
            return [];
        }
        $startIndex = max($startIndex, $endIndex - $limit + 1);
        $result = [];
        for ($index = $startIndex; $index <= $endIndex; ++$index) {
            $date = $this->atTimestamp($anchor, $anchor->getTimestamp() + $index * $seconds);
            if ($date > $after && $date < $before && (null === $this->until || $date <= $this->until)) {
                $result[] = $date;
            }
        }
        return $result;
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
     * Applies the interval the given number of times at once, in a single step.
     */
    private function multiple(DateTimeImmutable $from, int $times): DateTimeImmutable
    {
        /** @var DateInterval $interval */
        $interval = $this->dateInterval;

        $factor = 1 === $interval->invert ? -$times : $times;

        $scaled = DateInterval::createFromDateString(sprintf(
            '%d years %d months %d days %d hours %d minutes %d seconds %d microseconds',
            $factor * $interval->y,
            $factor * $interval->m,
            $factor * $interval->d,
            $factor * $interval->h,
            $factor * $interval->i,
            $factor * $interval->s,
            $factor * (int) round($interval->f * 1_000_000),
        ));

        // PHP 8.1 and 8.2 report a malformed spec with false instead of throwing; the spec is
        // built here from integers, so this only guards against an impossible state.
        if (!$scaled instanceof DateInterval) {
            throw new LogicException(sprintf('The interval could not be scaled by %d.', $times));
        }

        return $from->add($scaled);
    }

    private function add(DateTimeImmutable $date): DateTimeImmutable
    {
        if (null !== $this->seconds) {
            // setTimestamp() is used instead of modify(): the latter may return false on older PHP versions.
            return $this->atTimestamp($date, $date->getTimestamp() + $this->seconds);
        }

        /** @var DateInterval $interval */
        $interval = $this->dateInterval;

        return $date->add($interval);
    }

    private function atTimestamp(DateTimeImmutable $template, int $timestamp): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%s', $timestamp, $template->format('u')));
        if (false === $date) {
            throw new LogicException('The timestamp could not be reconstructed.');
        }

        return $date->setTimezone($template->getTimezone());
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
