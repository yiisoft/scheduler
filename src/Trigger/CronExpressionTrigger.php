<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Trigger;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Scheduler\Exception\LogicException;

use function date_default_timezone_get;
use function is_string;
use function sprintf;

/**
 * A trigger backed by a cron expression, evaluated by dragonmantank/cron-expression.
 */
final class CronExpressionTrigger implements TriggerRangeInterface
{
    private readonly CronExpression $cron;
    private readonly ?DateTimeZone $timezone;

    /**
     * @param DateTimeZone|string|null $timezone The timezone the expression is written in.
     * Defaults to the default timezone.
     */
    public function __construct(
        private readonly string $expression,
        DateTimeZone|string|null $timezone = null,
    ) {
        $this->cron = new CronExpression($expression);
        $this->timezone = is_string($timezone) ? ('' === $timezone ? null : new DateTimeZone($timezone)) : $timezone;
    }

    /**
     * The timezone is part of the description, and so of the identifier a task derives from it:
     * one expression read in two timezones names two different sets of run times, and a schedule
     * holding both has to tell them apart. An expression left to the default timezone keeps the
     * description it has always had.
     */
    public function __toString(): string
    {
        return null === $this->timezone
            ? sprintf('cron(%s)', $this->expression)
            : sprintf('cron(%s, %s)', $this->expression, $this->timezone->getName());
    }

    public function getNextRunDate(DateTimeImmutable $lastRun): ?DateTimeImmutable
    {
        $next = DateTimeImmutable::createFromInterface(
            $this->cron->getNextRunDate($lastRun, 0, false, $this->timezone()),
        );

        if ($next <= $lastRun) {
            throw new LogicException(
                sprintf('Cron expression "%s" produced a date that is not strictly after the last run.', $this->expression),
            );
        }

        return $next;
    }

    public function getNextRunDateAtOrAfter(DateTimeImmutable $date, ?DateTimeImmutable $phase = null): ?DateTimeImmutable
    {
        if (null !== $phase && $phase >= $date) {
            return $this->getNextRunDate($phase);
        }

        $next = DateTimeImmutable::createFromInterface(
            $this->cron->getNextRunDate($date, 0, true, $this->timezone()),
        );

        // An expression names a minute, so a date past the start of one is answered with that
        // minute, which is behind the date asked about. The run at that minute has been and
        // gone; the answer is the one after the date itself.
        if ($next < $date) {
            return $this->getNextRunDate($date);
        }

        return $next;
    }

    public function getRunDates(
        DateTimeImmutable $after,
        DateTimeImmutable $before,
        int $limit,
        ?DateTimeImmutable $phase = null,
    ): array {
        if ($limit <= 0) {
            throw new LogicException(sprintf('The limit must be greater than zero, "%d" given.', $limit));
        }

        $cursor = null !== $phase && $phase > $after ? $phase : $after;

        $dates = [];

        // The library walks the expression itself, so the cost is the batch and not the span it
        // is taken from: a backlog of a month is as cheap to open as a backlog of an hour.
        foreach ($this->cron->getMultipleRunDates($limit, $cursor, false, false, $this->timezone()) as $date) {
            $date = DateTimeImmutable::createFromInterface($date);

            if ($date >= $before) {
                break;
            }

            $dates[] = $date;
        }

        return $dates;
    }

    /**
     * The timezone the expression is read in. Not the timezone of the date asked about: one
     * restored from a checkpoint is in UTC whatever timezone it was saved in, and the expression
     * would silently change its meaning with it.
     */
    private function timezone(): string
    {
        return $this->timezone?->getName() ?? date_default_timezone_get();
    }
}
