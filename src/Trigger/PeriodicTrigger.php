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
 * A trigger firing at a fixed interval, anchored to a "from" date (by default the first time it is asked).
 *
 * When anchored, the run dates are the multiples of the interval counted from the anchor, so the
 * sequence never drifts: `from`, `from + 1 × interval`, `from + 2 × interval`, and so on.
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
            $next = $this->from->getTimestamp() + intdiv($elapsed, $this->seconds) * $this->seconds;
            $next = (new DateTimeImmutable())->setTimestamp($next)->setTimezone($lastRun->getTimezone());

            if ($next <= $lastRun) {
                $next = $next->setTimestamp($next->getTimestamp() + $this->seconds);
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
            return $date->setTimestamp($date->getTimestamp() + $this->seconds);
        }

        /** @var DateInterval $interval */
        $interval = $this->dateInterval;

        return $date->add($interval);
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
