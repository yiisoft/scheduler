<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

use Closure;
use DateInterval;
use ReflectionFunction;
use Throwable;
use Yiisoft\Queue\Message\MessageInterface;
use Yiisoft\Scheduler\Trigger\CronExpressionTrigger;
use Yiisoft\Scheduler\Trigger\PeriodicTrigger;
use Yiisoft\Scheduler\Trigger\TriggerInterface;

use function get_debug_type;
use function hash;
use function implode;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_object;
use function is_scalar;
use function is_string;
use function ksort;
use function sprintf;
use function str_contains;
use function var_export;

use const SORT_STRING;

/**
 * A task paired with the trigger deciding when it runs.
 *
 * The task itself may be a callable to invoke, the name of a console command to run,
 * or an object to push to the queue (when a queue producer is configured).
 */
final class RecurringTask
{
    /**
     * @param callable|object|string $task A callable to invoke, a console command name, or a queue message object.
     */
    private function __construct(
        private readonly TriggerInterface $trigger,
        private readonly mixed $task,
        private readonly string $id,
        private readonly ?Overlap $overlap = null,
    ) {}

    /**
     * @param callable|object|string $task A callable to invoke, a console command name, or a queue message object.
     */
    public static function cron(string $expression, callable|object|string $task, ?string $timezone = null): self
    {
        $trigger = new CronExpressionTrigger($expression, $timezone);

        // The expression alone leaves out the timezone it is read in, and two tasks differing
        // only in that one ran under a single identifier. The timezone comes from the trigger's
        // own description, which holds it only when there is one: an expression left to the
        // default timezone is described by the expression itself and keeps the identifier it
        // had, rather than every cron task in every schedule taking a new one.
        $description = (string) $trigger;

        if (sprintf('cron(%s)', $expression) === $description) {
            $description = $expression;
        }

        return new self($trigger, $task, self::generateId('cron', $description, $task));
    }

    /**
     * @param callable|object|string $task A callable to invoke, a console command name, or a queue message object.
     */
    public static function every(int|string|DateInterval $interval, callable|object|string $task): self
    {
        if (is_string($interval)) {
            $interval = self::parseInterval($interval);
        }

        $trigger = new PeriodicTrigger($interval);

        return new self($trigger, $task, self::generateId('every', (string) $trigger, $task));
    }

    /**
     * @param callable|object|string $task A callable to invoke, a console command name, or a queue message object.
     */
    public static function trigger(TriggerInterface $trigger, callable|object|string $task): self
    {
        return new self($trigger, $task, self::generateId('trigger', (string) $trigger, $task));
    }

    /**
     * Runs every minute.
     *
     * This and the named constructors below open a {@see CronBuilder} chain, which is finished
     * with the task to run: `RecurringTask::daily()->at('05:00')->run('report:daily')`. They
     * build a cron expression, so the task they return is the one the same expression passed to
     * {@see cron()} would give, identifier included.
     */
    public static function everyMinute(): CronBuilder
    {
        return CronBuilder::everyMinutes(1);
    }

    /**
     * Runs every given number of minutes, counted from the top of the hour. The number has to
     * divide an hour evenly: 1, 2, 3, 4, 5, 6, 10, 12, 15, 20 or 30.
     */
    public static function everyMinutes(int $minutes): CronBuilder
    {
        return CronBuilder::everyMinutes($minutes);
    }

    /**
     * Runs at :00, :05, :10 and so on.
     */
    public static function everyFiveMinutes(): CronBuilder
    {
        return CronBuilder::everyMinutes(5);
    }

    /**
     * Runs at :00, :10, :20 and so on.
     */
    public static function everyTenMinutes(): CronBuilder
    {
        return CronBuilder::everyMinutes(10);
    }

    /**
     * Runs at :00, :15, :30 and :45.
     */
    public static function everyFifteenMinutes(): CronBuilder
    {
        return CronBuilder::everyMinutes(15);
    }

    /**
     * Runs at :00 and :30.
     */
    public static function everyThirtyMinutes(): CronBuilder
    {
        return CronBuilder::everyMinutes(30);
    }

    /**
     * Runs on the hour, every hour.
     */
    public static function hourly(): CronBuilder
    {
        return CronBuilder::everyHours(1);
    }

    /**
     * Runs every given number of hours, on the hour, counted from midnight. The number has to
     * divide a day evenly: 1, 2, 3, 4, 6, 8 or 12.
     */
    public static function everyHours(int $hours): CronBuilder
    {
        return CronBuilder::everyHours($hours);
    }

    /**
     * Runs at midnight, every day.
     */
    public static function daily(): CronBuilder
    {
        return new CronBuilder('0 0 * * *');
    }

    /**
     * Runs at midnight on Mondays.
     *
     * The day follows the rest of the package, where `every('1 week')` runs on Mondays too.
     * Pass the day to {@see CronBuilder::on()} to say which one it is rather than rely on the
     * default.
     */
    public static function weekly(): CronBuilder
    {
        return new CronBuilder('0 0 * * 1');
    }

    /**
     * Runs at midnight on the first day of the month.
     */
    public static function monthly(): CronBuilder
    {
        return new CronBuilder('0 0 1 * *');
    }

    /**
     * Runs at midnight on the first day of January, April, July and October.
     */
    public static function quarterly(): CronBuilder
    {
        return new CronBuilder('0 0 1 1,4,7,10 *');
    }

    /**
     * Runs at midnight on the first day of January.
     */
    public static function yearly(): CronBuilder
    {
        return new CronBuilder('0 0 1 1 *');
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the task under an identifier of your choosing instead of the generated one.
     *
     * The generated identifier is derived from the description the trigger gives of itself and
     * from what the task is: the command name, the class of an object, the type and payload of
     * a queue message, or the file and line a closure is declared at. Tasks that are alike in
     * all of that need identifiers of their own to be in one schedule. Such are closures
     * created in a loop, which are one line of code however many of them there are, and objects
     * of one class differing in what they were constructed with, which the class name alone
     * does not tell apart.
     */
    public function withId(string $id): self
    {
        if ('' === $id) {
            throw new Exception\LogicException('The task id must not be empty.');
        }

        return new self($this->trigger, $this->task, $id, $this->overlap);
    }

    public function getTrigger(): TriggerInterface
    {
        return $this->trigger;
    }

    /**
     * Returns the task saying what becomes of a run of it that is due while an earlier one is
     * still going.
     *
     * The answer is left out of the generated identifier, so changing it leaves the executions
     * already recorded under this task where they are. A task that does not answer is answered
     * for by its schedule.
     */
    public function withOverlap(Overlap $overlap): self
    {
        return new self($this->trigger, $this->task, $this->id, $overlap);
    }

    /**
     * What this task says about a run of it due while an earlier one is still going, or null
     * where it says nothing and the schedule answers for it.
     */
    public function getOverlap(): ?Overlap
    {
        return $this->overlap;
    }

    /**
     * What this task is: what its trigger says of itself, and the work behind it. This is what
     * the generated identifier is derived from.
     *
     * As exact as a trigger's own description and no more. A trigger that leaves part of itself
     * out of that description leaves it out of here, so two tasks are told apart here as well
     * as their triggers can be told apart: `cron()` renders its expression and not its
     * timezone, and `every()` its interval and not the date it counts from.
     *
     * Not an identifier and no replacement for one. A task given an identifier of its own with
     * {@see withId()} keeps that identifier when either half of this changes, which is what
     * this is read for.
     */
    public function getDefinition(): string
    {
        return $this->trigger . ' ' . self::describeTask($this->task);
    }

    /**
     * The task to run: a callable, a console command name, or a queue message object.
     */
    public function getTask(): mixed
    {
        return $this->task;
    }

    /**
     * PHP 8.3 and later throw on a malformed interval string, earlier versions emit a warning
     * and return false; both are reported as the same package-level exception.
     */
    private static function parseInterval(string $interval): DateInterval
    {
        try {
            // The suppression operator silences the warning emitted on PHP 8.1 and 8.2;
            // the false result is handled right below.
            $parsed = @DateInterval::createFromDateString($interval);
            // @phpstan-ignore catch.neverThrown (PHP 8.3 and later throws here rather than returning false.)
        } catch (Throwable $e) {
            throw new Exception\LogicException('The interval string could not be parsed.', 0, $e);
        }

        if (!$parsed instanceof DateInterval) {
            throw new Exception\LogicException('The interval string could not be parsed.');
        }

        return $parsed;
    }

    /**
     * A stable identifier for the task, used as its key in a schedule and in checkpoints.
     *
     * A checkpoint tells the runs it already holds from the ones still to come by this
     * identifier, so two tasks sharing one would make a schedule skip a run that never
     * happened. Sixty-four bits put that out of reach of any schedule: a thousand tasks
     * collide with a probability of about one in thirty thousand billion.
     */
    private static function generateId(string $kind, string $triggerDescription, mixed $task): string
    {
        return hash('xxh3', $kind . ':' . $triggerDescription . ':' . self::describeTask($task));
    }

    private static function describeTask(mixed $task): string
    {
        if (is_string($task)) {
            return $task;
        }

        if ($task instanceof Closure) {
            $reflection = new ReflectionFunction($task);

            return sprintf('closure@%s:%d', $reflection->getFileName() ?: 'unknown', $reflection->getStartLine());
        }

        if (is_array($task)) {
            $first = $task[0] ?? null;
            $target = match (true) {
                is_object($first) => $first::class,
                is_scalar($first) => (string) $first,
                default => '',
            };

            $method = $task[1] ?? null;

            return sprintf('%s::%s', $target, is_string($method) ? $method : 'unknown');
        }

        // Messages of one class are told apart by their type and their payload: GenericMessage is
        // the class of every message built from a payload, and messages of one type differing only
        // in what they carry are different tasks. The metadata is left out, whether a message
        // acquired it as it was handled — an id, a delay, failure counts — or carried it from a
        // `withMeta()` call before it was scheduled: none of that says which task it is.
        if ($task instanceof MessageInterface) {
            return sprintf('%s(%s:%s)', $task::class, $task->getType(), self::describePayload($task->getPayload()));
        }

        if (is_object($task)) {
            return $task::class;
        }

        return get_debug_type($task);
    }

    /**
     * A message payload as a string that stands for it: one value, however deep, written out in a
     * fixed order so that the same payload always reads the same way. A payload holds nothing but
     * scalars, nulls and arrays of them; anything else is rejected rather than described, because
     * what an object or a resource is cannot be written the same way twice.
     *
     * @param string $path Where in the payload the value sits, for the message of the exception.
     */
    private static function describePayload(mixed $payload, string $path = ''): string
    {
        if (is_array($payload)) {
            return self::describeArrayPayload($payload, $path);
        }

        if (is_float($payload)) {
            return self::describeFloat($payload);
        }

        if (null !== $payload && !is_bool($payload) && !is_int($payload) && !is_string($payload)) {
            throw new Exception\LogicException(
                sprintf(
                    'A message payload holds nothing but scalars, nulls and arrays of them, but %s is of type "%s".',
                    '' === $path ? 'the payload' : sprintf('the value at %s', $path),
                    get_debug_type($payload),
                ),
            );
        }

        return var_export($payload, true);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private static function describeArrayPayload(array $payload, string $path): string
    {
        // By key, so that two payloads holding the same entries in a different order are one
        // payload. As strings, because comparing them as PHP does would read "01" and 1 as one
        // key and leave the two payloads in whatever order they were written in.
        ksort($payload, SORT_STRING);

        $parts = [];

        foreach ($payload as $key => $value) {
            // The key is written out the same way a value is, so that one holding an "=" or a
            // comma cannot read as the end of its own entry and the start of another.
            $written = var_export($key, true);

            $parts[] = $written . '=' . self::describePayload($value, $path . '[' . $written . ']');
        }

        return '[' . implode(',', $parts) . ']';
    }

    /**
     * A float written to seventeen significant digits, which is enough to tell any two apart.
     * `var_export()` writes one at the precision of the `serialize_precision` setting instead,
     * so the same payload would read two ways on two hosts, and at a low enough setting 0.3 and
     * 0.1 + 0.2 would read as one value.
     */
    private static function describeFloat(float $value): string
    {
        $written = sprintf('%.17G', $value);

        // A trailing ".0" where there is no point and no exponent, so that a float does not read
        // as the integer of the same value. INF and NAN are written as names and need none.
        return is_finite($value) && !str_contains($written, '.') && !str_contains($written, 'E')
            ? $written . '.0'
            : $written;
    }
}
