<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Planner;

use InvalidArgumentException;
use Yiisoft\Schedule\Config\OverlapPolicy;
use Yiisoft\Schedule\Integration\Async\ExecutionLedgerQueryInterface;

use function count;

/** Source-scoped, conservative admission calculation. The supplied query owns the source partition. */
final class AdmissionPolicy
{
    public function __construct(private readonly ExecutionLedgerQueryInterface $query, private readonly int $capacity = 16)
    {
        if ($capacity < 1) {
            throw new InvalidArgumentException('Async worker capacity must be positive.');
        }
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function activeCount(): int
    {
        return count($this->query->ownershipHoldingExecutions());
    }

    public function decide(string $taskKey, OverlapPolicy|string $overlap = OverlapPolicy::ALLOW): AdmissionDecision
    {
        $policy = OverlapPolicy::fromScalar($overlap);
        $active = $this->query->ownershipHoldingExecutions();
        if (count($active) >= $this->capacity) {
            return AdmissionDecision::deferCapacity();
        }
        if ($policy === OverlapPolicy::SKIP) {
            foreach ($active as $record) {
                if ($record->occurrence->taskKey === $taskKey) {
                    return AdmissionDecision::skipOverlap();
                }
            }
        }
        return AdmissionDecision::admit();
    }
}
