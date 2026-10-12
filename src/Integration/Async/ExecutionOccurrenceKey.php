<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use InvalidArgumentException;
use Stringable;

/** Stable identity of one configured schedule occurrence. */
final class ExecutionOccurrenceKey implements Stringable
{
    public function __construct(
        public readonly string $scheduleKey,
        public readonly string $taskKey,
        public readonly string $occurrenceId,
    ) {
        foreach ([$scheduleKey, $taskKey, $occurrenceId] as $value) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $value) !== 1) {
                throw new InvalidArgumentException('Execution occurrence key parts must be bounded portable identifiers.');
            }
        }
    }

    public function __toString(): string
    {
        return $this->scheduleKey . ':' . $this->taskKey . ':' . $this->occurrenceId;
    }

    public function equals(self $other): bool
    {
        return $this->scheduleKey === $other->scheduleKey
            && $this->taskKey === $other->taskKey
            && $this->occurrenceId === $other->occurrenceId;
    }
}
