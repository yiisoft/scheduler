<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use Yiisoft\Scheduler\Exception\LogicException;

use function array_keys;
use function array_replace;
use function get_debug_type;
use function implode;
use function is_a;
use function is_string;
use function sprintf;

/**
 * Which executor runs which command class.
 *
 * A command is matched by its exact class. A subclass is a class of its own and is run by
 * whatever is registered for it, or by nothing at all: an executor registered for a parent
 * never inherits work it was not written for, and a command class nobody registered is an
 * error rather than a guess. What runs a task is therefore decided by this map alone, and
 * reading it is reading the dispatch.
 */
final class ExecutorMap
{
    /**
     * @var array<class-string<CommandInterface>, ExecutorInterface>
     */
    private readonly array $executors;

    /**
     * @param array<array-key, mixed> $executors Each command class and what runs its commands.
     */
    public function __construct(array $executors = [])
    {
        foreach ($executors as $class => $executor) {
            if (!is_string($class) || !is_a($class, CommandInterface::class, true)) {
                throw new LogicException(
                    sprintf(
                        'An executor is registered for a command class, but "%s" does not implement "%s".',
                        is_string($class) ? $class : get_debug_type($class),
                        CommandInterface::class,
                    ),
                );
            }

            if (!$executor instanceof ExecutorInterface) {
                throw new LogicException(
                    sprintf(
                        'What runs the commands of "%s" has to be an "%s", and is of type "%s".',
                        $class,
                        ExecutorInterface::class,
                        get_debug_type($executor),
                    ),
                );
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                throw new LogicException(
                    sprintf(
                        'An executor is registered for the class of the commands it runs, and "%s" is not a class commands are of.',
                        $class,
                    ),
                );
            }
        }

        /** @var array<class-string<CommandInterface>, ExecutorInterface> $executors */
        $this->executors = $executors;
    }

    /**
     * The three kinds this package ships, each registered for its own class.
     *
     * A container is needed only by the commands naming a service rather than holding a
     * callable, and is the container of the process this map is built in.
     */
    public static function standard(?ContainerInterface $container = null): self
    {
        $processes = new ProcessExecutor();

        return new self([
            CallableCommand::class => new CallableExecutor($container),
            ProcessCommand::class => $processes,
            PhpCommand::class => new PhpExecutor($processes),
        ]);
    }

    /**
     * The executor registered for this command's class.
     *
     * @throws LogicException When nothing is registered for it. The command has not run and
     * will not: an unregistered class is a schedule that was never finished being written.
     */
    public function for(CommandInterface $command): ExecutorInterface
    {
        $executor = $this->executors[$command::class] ?? null;

        if (null === $executor) {
            throw new LogicException(
                sprintf(
                    'No executor is registered for "%s". Registered: %s.',
                    $command::class,
                    [] === $this->executors ? 'nothing' : implode(', ', array_keys($this->executors)),
                ),
            );
        }

        return $executor;
    }

    /**
     * The same map with these registrations added, replacing the ones for the same classes.
     *
     * @param array<array-key, mixed> $executors
     */
    public function with(array $executors): self
    {
        return new self(array_replace($this->executors, (new self($executors))->executors));
    }

    /**
     * @return array<class-string<CommandInterface>, ExecutorInterface>
     */
    public function all(): array
    {
        return $this->executors;
    }
}
