<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

/** Application-owned durable checkpoint for the last consumed schedule occurrence. */
interface ExecutionOccurrenceCheckpointInterface
{
    public function read(string $taskKey): ?string;

    public function initialize(string $taskKey, string $cursor): void;

    public function advance(string $taskKey, string $expectedCursor, string $nextCursor): void;

    public function skip(string $taskKey, string $expectedCursor, string $nextCursor, OccurrenceSkipReason $reason): void;
}
