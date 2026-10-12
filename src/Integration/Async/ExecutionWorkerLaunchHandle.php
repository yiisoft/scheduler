<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use InvalidArgumentException;

/** Bounded opaque value by which the parent may observe an application launch. */
final class ExecutionWorkerLaunchHandle
{
    public function __construct(public readonly string $value)
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,255}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Worker launch handle is invalid.');
        }
    }
}
