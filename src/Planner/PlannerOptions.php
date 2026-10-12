<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use InvalidArgumentException;

final class PlannerOptions
{
    public function __construct(
        public readonly int $admissionLimit = 16,
        public readonly float $idleWaitSeconds = 5.0,
        public readonly float $minimumWaitSeconds = 0.01,
        public readonly float $maximumWaitSeconds = 0.25,
        public readonly float $shutdownGraceSeconds = 1.0,
        public readonly int $candidateScanLimit = 256,
    ) {
        if ($admissionLimit < 1 || $candidateScanLimit < 1 || $idleWaitSeconds < 0 || $minimumWaitSeconds <= 0
            || $maximumWaitSeconds < $minimumWaitSeconds
            || !is_finite($idleWaitSeconds) || !is_finite($minimumWaitSeconds) || !is_finite($maximumWaitSeconds)
            || $shutdownGraceSeconds < 0 || !is_finite($shutdownGraceSeconds)) {
            throw new InvalidArgumentException('Planner options are invalid.');
        }
    }
}
