<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function is_finite;

/** Application-owned request for starting a fresh worker. */
final class ExecutionWorkerLaunchRequest
{
    public readonly float $launchStartedAt;

    public function __construct(
        public readonly ExecutionWorkerCapability $capability,
        public readonly ?WorkerLifecyclePolicy $lifecyclePolicy = null,
        ?float $launchStartedAt = null,
        MonotonicClockInterface $clock = new SystemMonotonicClock(),
    ) {
        $this->launchStartedAt = $launchStartedAt ?? $clock->now();
        if (!is_finite($this->launchStartedAt) || $this->launchStartedAt < 0.0) {
            throw new InvalidArgumentException('Worker launch start time is invalid.');
        }
    }
}
