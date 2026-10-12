<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async\Process;

use InvalidArgumentException;

/** Result of a successful process start, including its monotonic start boundary. */
final class ProcessLaunchResult
{
    public function __construct(
        public readonly ProcessHandleInterface $handle,
        public readonly float $startedAt,
    ) {
        if (!is_finite($startedAt) || $startedAt < 0.0) {
            throw new InvalidArgumentException('Process start time is invalid.');
        }
    }
}
