<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Trigger;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Scheduler\Exception\LogicException;

use function count;
use function date_default_timezone_get;
use function is_string;
use function sprintf;

/**
 * A trigger backed by a cron expression, evaluated by dragonmantank/cron-expression.
 *
 * An expression names a time on the clock rather than an amount of time that passes, so it
 * follows the clock through a change of it. A daily run at an hour that happens twice falls due
 * twice that day, and one at an hour the clock skips has no date that day at all: `0 2 * * *`
 * in Europe/Skopje is due twice on 25 October 2026, and in Australia/Lord_Howe, where the clock
 * moves by half an hour, it is not due on 4 October 2026. {@see PeriodicTrigger} counts an
 * interval of whole hours on the timestamp instead, where neither happens. Where that matters,
 * read the expression in UTC.
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

    /**
     * A cron expression always names a next run, or the expression itself is refused, so this
     * narrows the nullable date the interface allows.
     */
    public function getNextRunDate(DateTimeImmutable $lastRun): DateTimeImmutable
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

    public function getNextRunDateAtOrAfter(DateTimeImmutable $date, ?DateTimeImmutable $lastRun = null): DateTimeImmutable
    {
        if (null !== $lastRun && $lastRun >= $date) {
            return $this->getNextRunDate($lastRun);
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
        ?DateTimeImmutable $lastRun = null,
    ): array {
        if ($limit <= 0) {
            throw new LogicException(sprintf('The limit must be greater than zero, "%d" given.', $limit));
        }

        $cursor = null !== $lastRun && $lastRun > $after ? $lastRun : $after;

        $dates = [];
        $timezone = $this->timezone();

        // One run at a time rather than `getMultipleRunDates()`, which builds the whole limit
        // before anything can look at it: a limit of five million over a window of one minute
        // exhausted the memory limit on an answer of no dates at all. The library's own loop is
        // this, and it swallows the exception an impossible expression raises, so asking for
        // one run at a time is also what lets `0 0 30 2 *` fail here as it fails everywhere
        // else instead of reading as a sequence that has run out.
        $date = $cursor;

        while (count($dates) < $limit) {
            $date = DateTimeImmutable::createFromInterface(
                $this->cron->getNextRunDate($date, 0, false, $timezone),
            );

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
