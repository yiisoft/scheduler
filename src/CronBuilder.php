<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

use Cron\CronExpression;
use DateTimeZone;
use Exception;
use Stringable;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Trigger\CronExpressionTrigger;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function preg_split;
use function sort;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Builds a cron expression a field at a time, so that a schedule can be read without parsing
 * five positional fields in the head.
 *
 * ```php
 * RecurringTask::daily()->at('05:00')->run('report:daily');      // runs at 05:00
 * RecurringTask::everyFiveMinutes()->between('08:00', '17:00')   // every five minutes,
 *     ->run('cache:warm');                                       // from 08:00 to 16:55
 * RecurringTask::weekly()->on('monday')->at('09:30')->run(…);    // Mondays at 09:30
 * ```
 *
 * Every method returns a new instance, so a half-built expression can be kept and finished
 * in more than one way. The chain ends with {@see run()} for a task, {@see toTrigger()} for a
 * trigger alone, or {@see getExpression()} for the expression as a string.
 *
 * A time window is compiled into the expression rather than checked when the task is about to
 * run, so `schedule:list` and the next run date of the trigger account for it. That is what
 * limits {@see between()} to whole hours: a cron field cannot say "from 08:30".
 */
final class CronBuilder implements Stringable
{
    private const MINUTE = 0;
    private const HOUR = 1;
    private const DAY_OF_MONTH = 2;
    private const MONTH = 3;
    private const DAY_OF_WEEK = 4;

    private const FIELD_COUNT = 5;

    /**
     * Sunday is 0 because that is how cron numbers the days; `on('sunday')` and `on(7)` both
     * end up there.
     */
    private const DAY_NAMES = [
        'sunday' => 0,
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
    ];

    /**
     * @var list<string> The five fields of the expression, in cron order.
     */
    private readonly array $fields;

    /**
     * @param string $expression A cron expression to start from. The named constructors of
     * {@see RecurringTask} — `daily()`, `hourly()`, `weekly()` and the rest — are the usual way
     * in; this one is for an expression that already exists.
     * @param DateTimeZone|string|null $timezone The timezone the expression is written in.
     * Defaults to the default timezone.
     */
    public function __construct(
        string $expression = '* * * * *',
        private readonly DateTimeZone|string|null $timezone = null,
    ) {
        $fields = preg_split('/\s+/', trim($expression));

        if (!is_array($fields) || self::FIELD_COUNT !== count($fields)) {
            throw new LogicException(
                sprintf('A cron expression of five fields is expected, got "%s".', $expression),
            );
        }

        if (!CronExpression::isValidExpression($expression)) {
            throw new LogicException(sprintf('The cron expression "%s" is not valid.', $expression));
        }

        // The timezone is checked here rather than where the trigger is built, so that a typo in
        // it is reported at the step that introduced it and as the exception of this package.
        if (is_string($timezone) && '' !== $timezone) {
            try {
                new DateTimeZone($timezone);
            } catch (Exception $e) {
                throw new LogicException(sprintf('"%s" is not a timezone.', $timezone), 0, $e);
            }
        }

        $this->fields = $fields;
    }

    public function __toString(): string
    {
        return $this->getExpression();
    }

    /**
     * Runs every given number of minutes, counted from the top of the hour.
     *
     * The number has to divide an hour evenly, so that the last run of one hour and the first
     * of the next are an interval apart like every other pair: with a step of 7, :56 would be
     * followed by the next hour's :00 four minutes later.
     */
    public static function everyMinutes(int $minutes): self
    {
        if ($minutes < 1 || $minutes > 30 || 0 !== 60 % $minutes) {
            throw new LogicException(
                sprintf(
                    'An interval of %d minutes does not divide an hour evenly; use 1, 2, 3, 4, 5, 6, 10, 12, 15, 20 or 30.',
                    $minutes,
                ),
            );
        }

        return new self(1 === $minutes ? '* * * * *' : sprintf('*/%d * * * *', $minutes));
    }

    /**
     * Runs every given number of hours, on the hour, counted from midnight.
     *
     * The number has to divide a day evenly, for the reason {@see everyMinutes()} gives.
     */
    public static function everyHours(int $hours): self
    {
        if ($hours < 1 || $hours > 12 || 0 !== 24 % $hours) {
            throw new LogicException(
                sprintf(
                    'An interval of %d hours does not divide a day evenly; use 1, 2, 3, 4, 6, 8 or 12.',
                    $hours,
                ),
            );
        }

        return new self(1 === $hours ? '0 * * * *' : sprintf('0 */%d * * *', $hours));
    }

    /**
     * Runs at the given time of day, written as `HH:MM`.
     *
     * It replaces both the hour and the minute, so it belongs on a chain that already runs once
     * a day: one that starts daily, weekly, monthly, quarterly or yearly. On a chain repeating
     * within the day it would drop the repetition without saying so, and is refused — use
     * {@see atMinutes()} to place the runs of an hourly chain, and {@see atHours()} those of a
     * daily one.
     */
    public function at(string $time): self
    {
        [$hour, $minute] = self::parseTime($time);

        foreach ([self::MINUTE => 'minute', self::HOUR => 'hour'] as $position => $name) {
            if (!self::isOneValue($this->fields[$position])) {
                throw new LogicException(
                    sprintf(
                        'at() needs a chain that already runs once a day, but the %s field is "%s", so at() would drop runs without saying so; use atMinutes() or atHours() to place the runs of a repeating chain.',
                        $name,
                        $this->fields[$position],
                    ),
                );
            }
        }

        return $this
            ->withField(self::HOUR, (string) $hour)
            ->withField(self::MINUTE, (string) $minute);
    }

    /**
     * Runs at the given hours of the day, on the hour unless the minute is set otherwise.
     */
    public function atHours(int ...$hours): self
    {
        return $this->withField(self::HOUR, self::listField('hour', $hours, 0, 23));
    }

    /**
     * Runs at the given minutes of every hour the expression allows.
     */
    public function atMinutes(int ...$minutes): self
    {
        return $this->withField(self::MINUTE, self::listField('minute', $minutes, 0, 59));
    }

    /**
     * Runs on the given days of the week, named (`monday`) or numbered the way cron does
     * (0 or 7 for Sunday, 1 for Monday).
     */
    public function on(int|string ...$days): self
    {
        if ([] === $days) {
            throw new LogicException('At least one day of the week is expected.');
        }

        $numbers = array_map(self::parseDayOfWeek(...), $days);

        return $this->withDayOfWeek(self::compress($numbers));
    }

    /**
     * Runs from Monday to Friday.
     */
    public function weekdays(): self
    {
        return $this->on(1, 2, 3, 4, 5);
    }

    /**
     * Runs on Saturdays and Sundays.
     */
    public function weekends(): self
    {
        return $this->on(0, 6);
    }

    /**
     * Runs on the given days of the month.
     */
    public function onDays(int ...$days): self
    {
        return $this->withDayOfMonth(self::listField('day of the month', $days, 1, 31));
    }

    /**
     * Runs on the last day of the month, whichever day that is.
     */
    public function onLastDayOfMonth(): self
    {
        return $this->withDayOfMonth('L');
    }

    /**
     * Runs in the given months, numbered from 1 for January.
     */
    public function inMonths(int ...$months): self
    {
        return $this->withField(self::MONTH, self::listField('month', $months, 1, 12));
    }

    /**
     * Keeps only the runs falling within the given hours of the day, leaving whatever the
     * expression says about the minutes.
     *
     * It narrows the hours the expression already runs at rather than replacing them, so
     * `everyHours(6)->between('08:00', '17:00')` keeps the one run of the four that falls in
     * the window instead of turning the task into an hourly one. A window sharing no hour with
     * the expression is refused rather than silently given runs it never asked for.
     *
     * Both times are whole hours written as `HH:00`, the start is included and the end is not:
     * `everyFiveMinutes()->between('08:00', '17:00')` last runs at 16:55. A window whose end is
     * earlier than its start crosses midnight, so `between('22:00', '02:00')` covers 22, 23, 0
     * and 1 o'clock.
     */
    public function between(string $start, string $end): self
    {
        $window = self::windowHours($start, $end);
        $kept = array_values(array_filter(
            self::expandHours($this->fields[self::HOUR]),
            static fn(int $hour): bool => in_array($hour, $window, true),
        ));

        return $this->withHours($kept, sprintf('the window from %s to %s covers none of them', $start, $end));
    }

    /**
     * Keeps only the runs falling outside the given hours of the day, read as {@see between()}
     * reads it, and narrowing the same way.
     */
    public function unlessBetween(string $start, string $end): self
    {
        $window = self::windowHours($start, $end);
        $kept = array_values(array_filter(
            self::expandHours($this->fields[self::HOUR]),
            static fn(int $hour): bool => !in_array($hour, $window, true),
        ));

        return $this->withHours($kept, sprintf('the window from %s to %s covers all of them', $start, $end));
    }

    /**
     * Reads the expression in the given timezone instead of the default one.
     */
    public function timezone(DateTimeZone|string|null $timezone): self
    {
        return new self($this->getExpression(), $timezone);
    }

    public function getExpression(): string
    {
        return implode(' ', $this->fields);
    }

    public function toTrigger(): CronExpressionTrigger
    {
        return new CronExpressionTrigger($this->getExpression(), $this->timezone);
    }

    /**
     * Ends the chain with the task to run at the times built so far.
     *
     * The task is one of the three {@see RecurringTask::cron()} takes: a console command name,
     * a callable, or a queue message object. The identifier is the one the same expression
     * written by hand would produce.
     *
     * @param callable|object|string $task A callable to invoke, a console command name, or a queue message object.
     */
    public function run(callable|object|string $task): RecurringTask
    {
        $timezone = $this->timezone instanceof DateTimeZone ? $this->timezone->getName() : $this->timezone;

        return RecurringTask::cron($this->getExpression(), $task, $timezone);
    }

    /**
     * @return array{int, int} The hour and the minute of a `HH:MM` time.
     */
    private static function parseTime(string $time): array
    {
        if (1 !== preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            throw new LogicException(sprintf('A time written as "HH:MM" is expected, got "%s".', $time));
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            throw new LogicException(sprintf('The time "%s" is not a time of day.', $time));
        }

        return [$hour, $minute];
    }

    /**
     * @return non-empty-list<int> The hours a window covers: the start included, the end not,
     * counting through midnight when the end is the earlier of the two.
     */
    private static function windowHours(string $start, string $end): array
    {
        [$startHour, $startMinute] = self::parseTime($start);
        [$endHour, $endMinute] = self::parseTime($end);

        if (0 !== $startMinute || 0 !== $endMinute) {
            throw new LogicException(
                sprintf(
                    'A window of whole hours is expected, got "%s" to "%s": an hour of a cron expression cannot be entered part way through.',
                    $start,
                    $end,
                ),
            );
        }

        if ($startHour === $endHour) {
            throw new LogicException(
                sprintf('The window from %s to %s is empty; it has to span at least an hour.', $start, $end),
            );
        }

        $hours = [$startHour];

        for ($hour = ($startHour + 1) % 24; $hour !== $endHour; $hour = ($hour + 1) % 24) {
            $hours[] = $hour;
        }

        return $hours;
    }

    private static function parseDayOfWeek(int|string $day): int
    {
        if (is_int($day)) {
            if ($day < 0 || $day > 7) {
                throw new LogicException(sprintf('A day of the week is 0 to 7, got %d.', $day));
            }

            // Cron names Sunday both ways; one of them keeps the compressed field predictable.
            return 7 === $day ? 0 : $day;
        }

        $name = strtolower($day);

        if (!isset(self::DAY_NAMES[$name])) {
            throw new LogicException(sprintf('"%s" is not a day of the week.', $day));
        }

        return self::DAY_NAMES[$name];
    }

    /**
     * @param array<array-key, int> $values
     */
    private static function listField(string $what, array $values, int $min, int $max): string
    {
        if ([] === $values) {
            throw new LogicException(sprintf('At least one %s is expected.', $what));
        }

        foreach ($values as $value) {
            if ($value < $min || $value > $max) {
                throw new LogicException(
                    sprintf('A %s is %d to %d, got %d.', $what, $min, $max, $value),
                );
            }
        }

        return self::compress($values);
    }

    /**
     * Writes a set of numbers as a cron field, as the ranges of consecutive numbers it is made
     * of: 1, 2, 3, 4, 5 as `1-5`, and 0, 1, 22, 23 as `0,1,22,23`.
     *
     * @param non-empty-array<array-key, int> $values
     */
    private static function compress(array $values): string
    {
        sort($values);

        $parts = [];
        $start = $values[0];
        $previous = $start;

        foreach ($values as $value) {
            if ($value === $previous || $value === $previous + 1) {
                $previous = $value;

                continue;
            }

            $parts[] = self::range($start, $previous);
            $start = $value;
            $previous = $value;
        }

        $parts[] = self::range($start, $previous);

        return implode(',', $parts);
    }

    private static function range(int $start, int $end): string
    {
        if ($start === $end) {
            return (string) $start;
        }

        // Two numbers next to each other read better listed than written as a range of two.
        return $end === $start + 1
            ? sprintf('%d,%d', $start, $end)
            : sprintf('%d-%d', $start, $end);
    }

    /**
     * Whether a field names one value rather than several, which is what {@see at()} needs the
     * minute and the hour to be to replace them without changing how often the task runs.
     */
    private static function isOneValue(string $field): bool
    {
        return 1 === preg_match('/^\d{1,2}$/', $field);
    }

    /**
     * The hours an hour field matches, so that {@see between()} and {@see unlessBetween()} can
     * narrow it instead of replacing it.
     *
     * It reads what this builder writes — `*`, a step, and lists of numbers and ranges — which
     * covers the fields a chain can arrive with. Anything beyond that is refused rather than
     * guessed at.
     *
     * @return non-empty-list<int>
     */
    private static function expandHours(string $field): array
    {
        $hours = [];

        foreach (explode(',', $field) as $item) {
            if (1 !== preg_match('#^(?:(\*)|(\d{1,2})(?:-(\d{1,2}))?)(?:/(\d{1,2}))?$#', $item, $matches)) {
                throw new LogicException(
                    sprintf('The hour field "%s" is not one this builder can narrow.', $field),
                );
            }

            // An hour out of range, a step of zero and a bare number with a step are all refused
            // by the expression the constructor validated, so only the bounds are read here.
            $step = '' !== ($matches[4] ?? '') ? (int) $matches[4] : 1;
            $first = '' !== $matches[1] ? 0 : (int) $matches[2];
            $last = '' !== $matches[1] ? 23 : (int) ('' !== ($matches[3] ?? '') ? $matches[3] : $matches[2]);

            if ($first > $last) {
                throw new LogicException(
                    sprintf('The hour field "%s" counts backwards, which is not a window of hours.', $field),
                );
            }

            for ($hour = $first; $hour <= $last; $hour += $step) {
                $hours[] = $hour;
            }
        }

        /** @var non-empty-list<int> $expanded A field matches at least one hour or it is not one. */
        $expanded = array_values(array_unique($hours));

        return $expanded;
    }

    /**
     * @param list<int> $hours
     */
    private function withHours(array $hours, string $what): self
    {
        if ([] === $hours) {
            throw new LogicException(
                sprintf(
                    'The expression runs at %s o\'clock, and %s, so nothing would be left to run.',
                    implode(', ', self::expandHours($this->fields[self::HOUR])),
                    $what,
                ),
            );
        }

        return $this->withField(self::HOUR, self::compress($hours));
    }

    private function withDayOfWeek(string $value): self
    {
        self::assertOneDayField($this->fields[self::DAY_OF_MONTH], 'day of the month', 'week');

        return $this->withField(self::DAY_OF_WEEK, $value);
    }

    private function withDayOfMonth(string $value): self
    {
        self::assertOneDayField($this->fields[self::DAY_OF_WEEK], 'day of the week', 'month');

        return $this->withField(self::DAY_OF_MONTH, $value);
    }

    /**
     * Cron runs a task on a day matching either day field once both are narrowed, so setting
     * the second widens the schedule where the chain reads as narrowing it. The builder has no
     * way to write "both", so it refuses instead of producing a schedule nobody asked for.
     */
    private static function assertOneDayField(string $other, string $otherName, string $unit): void
    {
        if ('*' === $other) {
            return;
        }

        throw new LogicException(
            sprintf(
                'The %s is already set to "%s", and cron runs a task on a day matching either day field, so adding a day of the %s would make it run on both. Keep one of the two, or write the expression by hand.',
                $otherName,
                $other,
                $unit,
            ),
        );
    }

    private function withField(int $position, string $value): self
    {
        $fields = $this->fields;
        $fields[$position] = $value;

        return new self(implode(' ', $fields), $this->timezone);
    }
}
