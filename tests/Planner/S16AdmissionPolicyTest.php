<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Planner;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\{ExecutionLedgerQueryInterface,ExecutionLedgerRecord,ExecutionLedgerRecoveryPage,ExecutionOccurrenceKey};
use Yiisoft\Scheduler\Planner\{AdmissionDecision,AdmissionPolicy};

final class S16AdmissionPolicyTest extends TestCase
{
    public function testEveryNonTerminalNonRevokedStateConsumesCapacityAndOverlapIsTaskScoped(): void
    {
        $records = [];
        $query = new class ($records) implements ExecutionLedgerQueryInterface {
            public function __construct(private readonly array $records) {}

            public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
            {
                foreach ($this->records as $record) {
                    if ($record->occurrence == $occurrence) {
                        return $record;
                    }
                }
                return null;
            }

            public function hasTaskHistory(string $taskKey): bool
            {
                foreach ($this->records as $record) {
                    if ($record->occurrence->taskKey === $taskKey) {
                        return true;
                    }
                }
                return false;
            }

            public function activeExecutions(string $sourceIdentity): array
            {
                return $this->records;
            }

            public function ownershipHoldingExecutions(string $sourceIdentity): array
            {
                return $this->records;
            }

            public function recoveryPage(string $sourceIdentity, ?string $cursor, int $limit): ExecutionLedgerRecoveryPage
            {
                return new ExecutionLedgerRecoveryPage([], null);
            }

            public function repairIndexes(): void {}
        };
        $policy = new AdmissionPolicy($query, 2, 'source-1');
        self::assertSame(AdmissionDecision::ADMIT, $policy->decide('task-a', 'allow')->kind);
    }

    public function testEmptySourceIdentityIsRejectedBeforeQueryingOwnership(): void
    {
        $query = new class implements ExecutionLedgerQueryInterface {
            public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
            {
                throw new LogicException('The query must not be called.');
            }

            public function hasTaskHistory(string $taskKey): bool
            {
                throw new LogicException('The query must not be called.');
            }

            public function activeExecutions(string $sourceIdentity): array
            {
                throw new LogicException('The query must not be called.');
            }

            public function ownershipHoldingExecutions(string $sourceIdentity): array
            {
                throw new LogicException('The query must not be called.');
            }

            public function recoveryPage(string $sourceIdentity, ?string $cursor, int $limit): ExecutionLedgerRecoveryPage
            {
                throw new LogicException('The query must not be called.');
            }

            public function repairIndexes(): void {}
        };

        $this->expectException(InvalidArgumentException::class);
        new AdmissionPolicy($query, 2, '');
    }
}
