<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

/** Opaque capability for parent/recovery mutations, separate from a worker fence. */
final class ExecutionRecoveryFence
{
    public function __construct(public readonly string $token, public readonly int $generation = 1)
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $token) !== 1 || $generation < 1) {
            throw new InvalidArgumentException('Execution recovery fence is invalid.');
        }
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->token, $other->token) && $this->generation === $other->generation;
    }
}
