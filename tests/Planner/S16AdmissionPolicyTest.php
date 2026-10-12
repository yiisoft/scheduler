<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Planner;

use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\{ExecutionLedgerQueryInterface};
use Yiisoft\Scheduler\Planner\{AdmissionDecision,AdmissionPolicy};

final class S16AdmissionPolicyTest extends TestCase
{
    public function testEveryNonTerminalNonRevokedStateConsumesCapacityAndOverlapIsTaskScoped(): void
    {
        $records = [];
        $query = new class ($records) implements ExecutionLedgerQueryInterface {
            public function __construct(private readonly array $records) {}

            public function activeExecutions(): array
            {
                return $this->records;
            }

            public function ownershipHoldingExecutions(): array
            {
                return $this->records;
            }

            public function repairIndexes(): void {}
        };
        $policy = new AdmissionPolicy($query, 2);
        self::assertSame(AdmissionDecision::ADMIT, $policy->decide('task-a', 'allow')->kind);
    }
}
