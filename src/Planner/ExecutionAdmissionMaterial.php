<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Planner;

use Yiisoft\Schedule\Integration\Async\{ExecutionDispatchReservation,ExecutionFence,ExecutionLedgerRecord,ExecutionRecoveryFence,ExecutionWorkerCapability};

/** The immutable facts shared by durable admission and worker dispatch. */
final class ExecutionAdmissionMaterial
{
    public function __construct(
        public readonly ExecutionLedgerRecord $record,
        public readonly ?ExecutionWorkerCapability $capability,
        public readonly ExecutionDispatchReservation $reservation,
        public readonly ExecutionRecoveryFence $recoveryFence,
    ) {}

    public function fence(): ExecutionFence
    {
        return $this->record->fence;
    }
}
