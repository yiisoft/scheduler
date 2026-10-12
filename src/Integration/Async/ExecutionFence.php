<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use InvalidArgumentException;

/** Generation and opaque capability used to fence an admitted attempt. */
final class ExecutionFence
{
    public function __construct(public readonly int $generation, public readonly string $token)
    {
        if ($generation < 1 || preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Execution fence generation and token are invalid.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->generation === $other->generation && hash_equals($this->token, $other->token);
    }
}
