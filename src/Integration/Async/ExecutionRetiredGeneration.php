<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

/** Immutable diagnostic snapshot of an attempt retired by generation rotation. */
final class ExecutionRetiredGeneration
{
    public function __construct(
        public readonly ExecutionLedgerRecord $attempt,
        public readonly string $reason = 'generation-rotation',
        public readonly int $retiredAt = 0,
    ) {}
}
