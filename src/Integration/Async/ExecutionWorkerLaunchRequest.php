<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function is_finite;

/** Application-owned request for starting a fresh worker. */
final class ExecutionWorkerLaunchRequest
{
    public readonly float $launchStartedAt;
    public readonly ExecutionLaunchDescriptor $descriptor;

    public function __construct(
        public readonly ExecutionWorkerCapability $capability,
        public readonly ?WorkerLifecyclePolicy $lifecyclePolicy = null,
        ?float $launchStartedAt = null,
        MonotonicClockInterface $clock = new SystemMonotonicClock(),
        ?ExecutionLaunchDescriptor $descriptor = null,
    ) {
        // This value is created before command construction and therefore before
        // the external start boundary. It contains no secret capability data.
        if ($capability->launchDescriptor === null) {
            throw new InvalidArgumentException('Worker capability launch descriptor is missing.');
        }
        if ($descriptor !== null && !$descriptor->equals($capability->launchDescriptor)) {
            throw new InvalidArgumentException('Worker launch descriptor does not match its capability.');
        }
        $this->descriptor = $descriptor ?? $capability->launchDescriptor;
        if ($this->descriptor->scopeKind !== ExecutionLaunchDescriptor::SCOPE_KIND
            || $this->descriptor->role !== ExecutionLaunchDescriptor::ROLE_WORKER
            || $this->descriptor->executionId !== $capability->executionId
            || $this->descriptor->generation !== $capability->generation
            || $this->descriptor->occurrence === null
            || !$this->descriptor->occurrence->equals($capability->occurrence)) {
            throw new InvalidArgumentException('Worker launch descriptor does not match its capability.');
        }
        $this->launchStartedAt = $launchStartedAt ?? $clock->now();
        if (!is_finite($this->launchStartedAt) || $this->launchStartedAt < 0.0) {
            throw new InvalidArgumentException('Worker launch start time is invalid.');
        }
    }
}
