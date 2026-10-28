<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

/** Same-boundary wall and monotonic command-start samples. */
final class ExecutionStartObservation
{
    public function __construct(public readonly int $wallMicroseconds, public readonly int $monotonicNanoseconds)
    {
        if ($wallMicroseconds < 0 || $monotonicNanoseconds < 0) {
            throw new InvalidArgumentException('Start observation is invalid.');
        }
    }

    /** @deprecated Use wallMicroseconds; retained only for source compatibility. */
    public function wallSeconds(): float
    {
        return $this->wallMicroseconds / 1000000.0;
    }
}
