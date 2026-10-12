<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

/** Immutable policy for supervising a retained parent worker process. */
final class WorkerLifecyclePolicy
{
    public function __construct(
        public readonly ?float $softSeconds = null,
        public readonly ?float $hardSeconds = null,
    ) {
        foreach ([$softSeconds, $hardSeconds] as $value) {
            if ($value !== null && (!is_finite($value) || $value <= 0)) {
                throw new InvalidArgumentException('Worker lifecycle deadlines must be positive finite seconds.');
            }
        }
        if ($softSeconds !== null && $hardSeconds !== null && $hardSeconds < $softSeconds) {
            throw new InvalidArgumentException('Worker hard lifecycle deadline must be greater than or equal to soft deadline.');
        }
    }
}
