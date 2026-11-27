<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use Closure;
use ReflectionFunction;
use Yiisoft\Scheduler\Exception\LogicException;

use function is_object;
use function is_string;
use function sprintf;

/**
 * Application code to call.
 *
 * Given a callable, the command holds that callable and the process holding the command is the
 * one that calls it. Given the name of a service and a method, it holds the two names, and the
 * process that runs it asks its container for the service: the dependencies are then the ones
 * of the process doing the work rather than the ones of the process that planned it.
 */
final class CallableCommand implements CommandInterface
{
    /**
     * @param array{string, string}|null $service The service and method to ask a container for.
     */
    private function __construct(
        private readonly ?Closure $callable,
        private readonly ?array $service,
        private readonly string $description,
    ) {}

    public function __toString(): string
    {
        return sprintf('callable(%s)', $this->description);
    }

    /**
     * A callable this process already holds.
     */
    public static function call(callable $callable): self
    {
        return new self(Closure::fromCallable($callable), null, self::describe($callable));
    }

    /**
     * A method of a service, resolved by the container of whichever process runs the command.
     *
     * @param string $service The container entry, usually a class name.
     * @param string $method The method to call on it.
     */
    public static function of(string $service, string $method = '__invoke'): self
    {
        if ('' === $service || '' === $method) {
            throw new LogicException('A service and a method are both needed to name a callable.');
        }

        return new self(null, [$service, $method], sprintf('%s::%s', $service, $method));
    }

    /**
     * The callable this command holds, or null when it names a service instead.
     */
    public function getCallable(): ?Closure
    {
        return $this->callable;
    }

    /**
     * The service and method to ask a container for, or null when the command holds a callable.
     *
     * @return array{string, string}|null
     */
    public function getService(): ?array
    {
        return $this->service;
    }

    private static function describe(callable $callable): string
    {
        if ($callable instanceof Closure) {
            $reflection = new ReflectionFunction($callable);

            return sprintf('closure@%s:%d', $reflection->getFileName() ?: 'unknown', $reflection->getStartLine());
        }

        if (is_string($callable)) {
            return $callable;
        }

        if (is_object($callable)) {
            return sprintf('%s::__invoke', $callable::class);
        }

        // The same way a task given as an array is described, so that the two forms of writing
        // down one method read alike.
        $target = $callable[0] ?? null;
        $method = $callable[1] ?? null;

        return sprintf(
            '%s::%s',
            is_object($target) ? $target::class : (string) $target,
            is_string($method) ? $method : 'unknown',
        );
    }
}
