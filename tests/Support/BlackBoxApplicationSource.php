<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Support;

use LogicException;
use Yiisoft\Schedule\Integration\Async\ExecutionAdmission;
use Yiisoft\Schedule\Integration\Async\ExecutionOccurrenceCandidate;
use Yiisoft\Schedule\Integration\Async\ExecutionOccurrenceSourceInterface;
use Yiisoft\Schedule\Integration\Async\OccurrenceSkipReason;

final class BlackBoxApplicationSource implements ExecutionOccurrenceSourceInterface
{
    public function next(): ?ExecutionOccurrenceCandidate
    {
        throw new LogicException('The observation fixture does not dispatch a source candidate.');
    }

    public function acknowledge(ExecutionOccurrenceCandidate $candidate, ExecutionAdmission $admission): void
    {
        throw new LogicException('The observation fixture does not acknowledge a source candidate.');
    }

    public function skip(ExecutionOccurrenceCandidate $candidate, OccurrenceSkipReason $reason): void
    {
        throw new LogicException('The observation fixture does not skip a source candidate.');
    }
}
