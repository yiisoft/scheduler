<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Trigger;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Schedule\Exception\LogicException;
use InvalidArgumentException;

use function is_string;
use function sprintf;
use function count;

/**
 * A trigger backed by a cron expression, evaluated by dragonmantank/cron-expression.
 */
final class CronExpressionTrigger implements TriggerRangeInterface
{
    private readonly CronExpression $cron;
    private readonly ?DateTimeZone $timezone;

    public function __construct(
        private readonly string $expression,
        DateTimeZone|string|null $timezone = null,
        private readonly ?DateTimeImmutable $from = null,
        private readonly ?DateTimeImmutable $until = null,
    ) {
        $this->cron = new CronExpression($expression);
        $this->timezone = is_string($timezone) ? ('' === $timezone ? null : new DateTimeZone($timezone)) : $timezone;
    }

    public function __toString(): string
    {
        return sprintf('cron(%s)', $this->expression);
    }

    public function getNextRunDateAtOrAfter(DateTimeImmutable $date, ?DateTimeImmutable $phase = null): ?DateTimeImmutable
    {
        $lowerBound = $this->from !== null && $date < $this->from ? $this->from : $date;
        $probe = $this->from !== null && $date < $this->from
            ? $this->from->modify('-1 second')
            : $date;
        $next = DateTimeImmutable::createFromInterface(
            $this->cron->getNextRunDate($probe, 0, true, $this->timezone?->getName()),
        );
        if ($next < $lowerBound) {
            $next = DateTimeImmutable::createFromInterface(
                $this->cron->getNextRunDate($lowerBound, 0, false, $this->timezone?->getName()),
            );
        }

        // A phase is an acknowledged cursor: an occurrence at the cursor must not be returned again.
        // Compare the immutable dates themselves so fractional seconds are not lost.
        if (null !== $phase && $next <= $phase) {
            $next = DateTimeImmutable::createFromInterface(
                $this->cron->getNextRunDate($phase, 0, false, $this->timezone?->getName()),
            );
            while ($next <= $phase) {
                $next = DateTimeImmutable::createFromInterface(
                    $this->cron->getNextRunDate($next, 0, false, $this->timezone?->getName()),
                );
            }
        }

        return $this->until !== null && $next > $this->until ? null : $next;
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

        $dates = [];
        $cursor = $before;
        $allowCurrentDate = true;
        while (count($dates) < $limit) {
            $date = DateTimeImmutable::createFromInterface(
                $this->cron->getPreviousRunDate($cursor, 0, $allowCurrentDate, $this->timezone?->getName()),
            );
            if ($date >= $before) {
                $date = DateTimeImmutable::createFromInterface(
                    $this->cron->getPreviousRunDate($date, 0, false, $this->timezone?->getName()),
                );
            }
            if ($date <= $after || ($this->from !== null && $date < $this->from)) {
                break;
            }
            if (null === $this->until || $date <= $this->until) {
                $dates[] = $date;
            }
            $cursor = $date;
            $allowCurrentDate = false;
        }
        return array_reverse($dates);
    }

    public function getNextRunDate(DateTimeImmutable $lastRun): ?DateTimeImmutable
    {
        if ($this->until !== null && $lastRun >= $this->until) {
            return null;
        }
        $lowerBound = $this->from !== null && $lastRun < $this->from ? $this->from : $lastRun;
        $probe = $this->from !== null && $lastRun < $this->from
            ? $this->from->modify('-1 second')
            : $lastRun;
        $next = DateTimeImmutable::createFromInterface(
            $this->cron->getNextRunDate($probe, 0, false, $this->timezone?->getName()),
        );

        if ($next < $lowerBound || $next <= $lastRun) {
            $next = DateTimeImmutable::createFromInterface(
                $this->cron->getNextRunDate($lowerBound, 0, false, $this->timezone?->getName()),
            );
        }

        if ($next <= $lastRun) {
            throw new LogicException(
                sprintf('Cron expression "%s" produced a date that is not strictly after the last run.', $this->expression),
            );
        }

        if ($this->until !== null && $next > $this->until) {
            return null;
        }
        return $next;
    }
}
