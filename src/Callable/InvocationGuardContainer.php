<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Callable;

use Closure;
use LogicException;
use Psr\Container\ContainerInterface;
use RuntimeException;

/** A process-local container fence used while resolving callable dependencies. */
final class InvocationGuardContainer implements ContainerInterface
{
    private readonly Closure $guard;

    public function __construct(private readonly ContainerInterface $container, callable $guard)
    {
        $this->guard = Closure::fromCallable($guard);
    }

    public function __serialize(): array
    {
        throw new LogicException('Invocation guard containers cannot be serialized.');
    }

    public function has(string $id): bool
    {
        $this->before();
        try {
            return $this->container->has($id);
        } finally {
            $this->after();
        }
    }

    public function get(string $id): mixed
    {
        $this->before();
        try {
            return $this->container->get($id);
        } finally {
            $this->after();
        }
    }

    private function before(): void
    {
        $result = ($this->guard)();
        if ($result === false) {
            throw new RuntimeException('Invocation guard rejected access.');
        }
    }

    private function after(): void
    {
        $result = ($this->guard)();
        if ($result === false) {
            throw new RuntimeException('Invocation guard rejected access.');
        }
    }
}
