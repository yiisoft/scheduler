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
use function is_array;
use function is_object;
use function is_string;
use function sprintf;

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
    ) {}

    /**
     * @param callable|object|string $task A callable to invoke, a console command name, or a queue message object.
     */
    public static function cron(string $expression, callable|object|string $task, ?string $timezone = null): self
    {
        return new self(new CronExpressionTrigger($expression, $timezone), $task, self::generateId('cron', $expression, $task));
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

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the task under an identifier of your choosing instead of the generated one.
     *
     * The generated identifier is derived from the trigger and from what the task is: the
     * command name, the class of an object, the type of a queue message, or the file and line
     * a closure is declared at. Tasks that are alike in all of that, such as closures created
     * in a loop or messages differing only in their payload, need identifiers of their own to
     * be in one schedule.
     */
    public function withId(string $id): self
    {
        if ('' === $id) {
            throw new Exception\LogicException('The task id must not be empty.');
        }

        return new self($this->trigger, $this->task, $id);
    }

    public function getTrigger(): TriggerInterface
    {
        return $this->trigger;
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
     */
    private static function generateId(string $kind, string $triggerDescription, mixed $task): string
    {
        return hash('crc32c', $kind . ':' . $triggerDescription . ':' . self::describeTask($task));
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
            $target = is_object($task[0] ?? null) ? $task[0]::class : (string) ($task[0] ?? '');

            $method = $task[1] ?? null;

            return sprintf('%s::%s', $target, is_string($method) ? $method : 'unknown');
        }

        // Messages of one class are told apart by their type: GenericMessage is the class of
        // every message built from a payload.
        if ($task instanceof MessageInterface) {
            return sprintf('%s(%s)', $task::class, $task->getType());
        }

        if (is_object($task)) {
            return $task::class;
        }

        return get_debug_type($task);
    }
}
