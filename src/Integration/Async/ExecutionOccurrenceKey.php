<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

final class ExecutionOccurrenceKey
{
    public function __construct(
        public readonly string $taskKey,
        public readonly string $occurrenceId,
    ) {
        foreach ([$taskKey, $occurrenceId] as $value) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $value) !== 1) {
                throw new InvalidArgumentException('Execution occurrence key parts must be bounded portable identifiers.');
            }
        }
    }

    public function __toString(): string
    {
        return $this->taskKey . ':' . $this->occurrenceId;
    }

    public function equals(self $other): bool
    {
        return $this->taskKey === $other->taskKey && $this->occurrenceId === $other->occurrenceId;
    }
}
