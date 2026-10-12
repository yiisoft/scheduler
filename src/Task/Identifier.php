<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Task;

use InvalidArgumentException;
use Stringable;

/** An identifier which is stable when serialized. */
abstract class Identifier implements Stringable
{
    final public function __construct(public readonly string $value)
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $value)) {
            throw new InvalidArgumentException('Identifier must be 1-128 characters and contain only letters, digits, ., _, or -.');
        }
    }

    final public function __toString(): string
    {
        return $this->value;
    }
}
