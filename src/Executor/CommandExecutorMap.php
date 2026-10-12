<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use InvalidArgumentException;
use ReflectionClass;

/** Process-local exact command-class to executor bindings. */
final class CommandExecutorMap
{
    /** @var array<class-string<Command>, ExecutorInterface> */
    private readonly array $executors;

    /** @param array<class-string<Command>, ExecutorInterface> $executors */
    public function __construct(array $executors = [])
    {
        foreach ($executors as $class => $executor) {
            if (!is_a($class, Command::class, true)) {
                throw new InvalidArgumentException('Executor map keys must be concrete Command classes.');
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface()) {
                throw new InvalidArgumentException('Executor map keys must be concrete Command classes.');
            }
            if (!$executor instanceof ExecutorInterface) {
                throw new InvalidArgumentException('Executor map values must implement ExecutorInterface.');
            }
        }
        $this->executors = $executors;
    }

    public function executorFor(Command $command): ExecutorInterface
    {
        $executor = $this->executors[$command::class] ?? null;
        if ($executor === null) {
            throw new InvalidArgumentException('No executor is registered for command class ' . $command::class . '.');
        }
        return $executor;
    }

    public function get(Command $command): ExecutorInterface
    {
        return $this->executorFor($command);
    }

    /** @return array<class-string<Command>, ExecutorInterface> */
    public function all(): array
    {
        return $this->executors;
    }

    /** @param array<class-string<Command>, ExecutorInterface> $overlay */
    public function with(array $overlay): self
    {
        $validatedOverlay = new self($overlay);
        return new self(array_replace($this->executors, $validatedOverlay->executors));
    }
}
