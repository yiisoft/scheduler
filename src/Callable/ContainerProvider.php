<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Callable;

use Closure;
use InvalidArgumentException;
use LogicException;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Lazily resolves the process-local container used by callable execution.
 */
final class ContainerProvider
{
    private const STATE_UNRESOLVED = 0;
    private const STATE_RESOLVING = 1;
    private const STATE_RESOLVED = 2;
    private const STATE_FAILED = 3;

    private readonly ?Closure $factory;
    private int $state;
    private ?Throwable $failure = null;
    private ?ContainerInterface $resolved = null;

    public function __construct(ContainerInterface|callable|null $container = null)
    {
        if ($container instanceof ContainerInterface) {
            $this->factory = null;
            $this->state = self::STATE_RESOLVED;
            $this->resolved = $container;
            return;
        }

        $this->factory = $container === null ? null : Closure::fromCallable($container);
        $this->state = self::STATE_UNRESOLVED;
    }

    /** The provider is process-local and cannot cross a serialization boundary. */
    public function __serialize(): array
    {
        throw new LogicException('Container providers cannot be serialized.');
    }

    public function resolve(): ContainerInterface
    {
        if ($this->state === self::STATE_RESOLVED) {
            return $this->resolved ?? throw new LogicException('Container provider has no resolved container.');
        }

        if ($this->state === self::STATE_FAILED) {
            throw $this->failure ?? new LogicException('Container provider resolution failed.');
        }

        if ($this->state === self::STATE_RESOLVING) {
            $this->fail(new LogicException('Container provider resolution is recursive.'));
        }

        $this->state = self::STATE_RESOLVING;

        try {
            $container = $this->factory === null ? new EmptyContainer() : ($this->factory)();
            if (!$container instanceof ContainerInterface) {
                throw new InvalidArgumentException('Container provider factory must return a PSR-11 container.');
            }

            $this->resolved = $container;
            $this->state = self::STATE_RESOLVED;
            return $container;
        } catch (Throwable $exception) {
            $this->fail($exception);
        }
    }

    private function fail(Throwable $exception): never
    {
        $this->failure = $exception;
        $this->state = self::STATE_FAILED;
        throw $exception;
    }
}
