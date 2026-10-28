<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use InvalidArgumentException;

/** Result of a successful process start, including its monotonic start boundary. */
final class ProcessLaunchResult
{
    public function __construct(
        public readonly ProcessHandleInterface $handle,
        /** Actual monotonic process-start time; null only for incomplete reattachments. */
        public readonly ?float $startedAt,
        public readonly ?int $wallStartedAtMicros = null,
        public readonly ?ProcessIdentity $identity = null,
    ) {
        if (($startedAt !== null && (!is_finite($startedAt) || $startedAt < 0.0)) || ($wallStartedAtMicros !== null && $wallStartedAtMicros < 0)) {
            throw new InvalidArgumentException('Process start time is invalid.');
        }
    }
}
