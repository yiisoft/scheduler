<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;
use Yiisoft\Scheduler\ScalarMetadata;

final class ExecutionIdentity
{
    public function __construct(
        public readonly string $taskKey,
        public readonly string $occurrenceId,
        public readonly string $attemptId,
        public readonly string $runId,
        public readonly string $executionId,
    ) {
        foreach ([$taskKey, $occurrenceId, $attemptId, $runId, $executionId] as $value) {
            ScalarMetadata::assertSupported($value);
            if (!preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $value)) {
                throw new InvalidArgumentException('Correlation identifiers must be bounded and use portable characters.');
            }
        }
    }
}
