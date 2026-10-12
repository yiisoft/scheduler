<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Callable;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Yiisoft\Injector\Injector;

use function array_is_list;
use function array_keys;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/** Adapts an already selected callable to Yii's named-argument injector. */
final class CallableFactory
{
    private readonly Injector $injector;

    public function __construct(ContainerInterface $container)
    {
        $this->injector = new Injector($container);
    }

    /**
     * @param callable $callable
     * @return callable
     */
    public function create(callable $callable): callable
    {
        return function (mixed ...$arguments) use ($callable): mixed {
            if (array_is_list($arguments) && $arguments !== []) {
                throw new InvalidArgumentException('Callable arguments must be named.');
            }
            foreach (array_keys($arguments) as $name) {
                $value = $arguments[$name];
                if (!is_string($name) || ($value !== null && !is_bool($value) && !is_int($value) && !is_float($value) && !is_string($value))) {
                    throw new InvalidArgumentException('Callable arguments must be named scalar values.');
                }
            }
            return $this->injector->invoke($callable, $arguments);
        };
    }
}
