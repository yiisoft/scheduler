<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

/** Application-owned durable checkpoint for the last consumed schedule occurrence. */
interface ExecutionOccurrenceCheckpointInterface
{
    public function read(string $scheduleKey, string $taskKey): ?string;

    public function initialize(string $scheduleKey, string $taskKey, string $cursor): void;

    public function advance(string $scheduleKey, string $taskKey, string $expectedCursor, string $nextCursor): void;

    public function skip(string $scheduleKey, string $taskKey, string $expectedCursor, string $nextCursor, OccurrenceSkipReason $reason): void;
}
