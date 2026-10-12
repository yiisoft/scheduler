<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use InvalidArgumentException;

/** Opaque capability for parent/recovery mutations, separate from a worker fence. */
final class ExecutionRecoveryFence
{
    public function __construct(public readonly string $token)
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Execution recovery fence is invalid.');
        }
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->token, $other->token);
    }
}
