<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function in_array;
use function is_string;

/** Durable ownership facts for the standard command nested in a worker attempt. */
final class ExecutionCommandScope
{
    public function __construct(
        public readonly ExecutionLaunchDescriptor $descriptor,
        public readonly ExecutionDispatchReservation $reservation,
        public readonly ?ExecutionLaunchObservation $observation = null,
    ) {
        if ($descriptor->role !== ExecutionLaunchDescriptor::ROLE_COMMAND
            || $descriptor->rootDescriptor === null
            || $descriptor->occurrence === null
            || $descriptor->generation < 1) {
            throw new InvalidArgumentException('Command scope requires a bound command descriptor.');
        }
        if ($observation?->launchHandle !== null) {
            throw new InvalidArgumentException('Live launch handles cannot be serialized in a command scope.');
        }
        if (in_array($reservation->launchPhase, [ExecutionDispatchReservation::PREPARED, ExecutionDispatchReservation::STARTING], true)
            && $observation !== null) {
            throw new InvalidArgumentException('Unpublished command reservation has an observation.');
        }
        if ($reservation->launchPhase === ExecutionDispatchReservation::OBSERVED
            && ($observation === null
                || $observation->processIdentity === null
                || $observation->startObservation === null
                || $observation->pid !== $observation->processIdentity->pid)) {
            throw new InvalidArgumentException('Observed command reservation requires a complete receipt or confirmed closure.');
        }
        if ($observation !== null && $observation->pid !== null
            && ($observation->processIdentity === null || $observation->pid !== $observation->processIdentity->pid)) {
            throw new InvalidArgumentException('Command observation identity is inconsistent.');
        }
    }

    public function transition(
        ExecutionDispatchReservation|string $reservation = 'keep',
        ExecutionLaunchObservation|string|null $observation = 'keep',
    ): self {
        if (is_string($reservation) && $reservation !== 'keep' || is_string($observation) && $observation !== 'keep') {
            throw new InvalidArgumentException('Command scope transition sentinel is invalid.');
        }
        return new self(
            $this->descriptor,
            $reservation === 'keep' ? $this->reservation : $reservation,
            $observation === 'keep' ? $this->observation : $observation,
        );
    }
}
