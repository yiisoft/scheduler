<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use Closure;
use InvalidArgumentException;
use LogicException;
use Yiisoft\Scheduler\ScalarMetadata;

use function is_string;
use function is_bool;
use function is_float;
use function is_int;

/** A process-local callable declaration used by the typed configuration DSL. */
final class CallableCommand implements Command
{
    private readonly Closure $callable;

    /** @var array<string, mixed> */
    private readonly array $arguments;

    /** @param array<array-key, mixed> $arguments */
    public function __construct(callable $callable, array $arguments = [])
    {
        /** @var array<string, mixed> $namedArguments */
        $namedArguments = [];
        foreach ($arguments as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Callable arguments must use string names.');
            }
            if ($value !== null && !is_bool($value) && !is_int($value) && !is_float($value) && !is_string($value)) {
                throw new InvalidArgumentException('Callable arguments must be scalar values.');
            }
            $namedArguments[$key] = $value;
        }
        ScalarMetadata::assertAggregateSupported($namedArguments);
        $this->callable = Closure::fromCallable($callable);
        $this->arguments = $namedArguments;
    }

    public function __serialize(): array
    {
        throw new LogicException('Callable commands cannot be serialized.');
    }

    public function callable(): Closure
    {
        return $this->callable;
    }

    /** @return array<string, mixed> */
    public function arguments(): array
    {
        return $this->arguments;
    }
}
