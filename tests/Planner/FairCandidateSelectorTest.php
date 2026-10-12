<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Planner;

use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\TimeoutPolicy;
use Yiisoft\Scheduler\Integration\Async\{ExecutionBinding, ExecutionIdentity, ExecutionOccurrenceCandidate, ExecutionOccurrenceKey};
use Yiisoft\Scheduler\Planner\FairCandidateSelector;
use InvalidArgumentException;

final class FairCandidateSelectorTest extends TestCase
{
    public function testRepeatedBoundedSelectionsRotateTasksWithoutConsumingCandidates(): void
    {
        $candidates = [$this->candidate('a', 'a-1'), $this->candidate('b', 'b-1')];
        $selector = new FairCandidateSelector();

        self::assertSame(['a', 'b', 'a'], [
            $selector->select($candidates, 1)[0]->occurrence->taskKey,
            $selector->select($candidates, 1)[0]->occurrence->taskKey,
            $selector->select($candidates, 1)[0]->occurrence->taskKey,
        ]);
        self::assertSame('a-1', $candidates[0]->occurrence->occurrenceId);
    }

    public function testSelectionIsDeterministicByTaskAndOccurrenceOrder(): void
    {
        $selector = new FairCandidateSelector();
        $candidates = [
            $this->candidate('b', 'b-2'),
            $this->candidate('a', 'a-2'),
            $this->candidate('b', 'b-1'),
            $this->candidate('a', 'a-1'),
        ];

        self::assertSame(['a-1', 'b-1', 'a-2'], array_map(
            static fn(ExecutionOccurrenceCandidate $candidate): string => $candidate->occurrence->occurrenceId,
            $selector->select($candidates, 3),
        ));
    }

    public function testEmptyAndZeroLimitsAreSafeAndNegativeLimitIsRejected(): void
    {
        $selector = new FairCandidateSelector();
        $candidate = $this->candidate('a', 'a-1');

        self::assertSame([], $selector->select([], 1));
        self::assertSame([], $selector->select([$candidate], 0));
        $this->expectException(InvalidArgumentException::class);
        $selector->select([$candidate], -1);
    }

    public function testBoundedPassDoesNotStarveASecondTask(): void
    {
        $selector = new FairCandidateSelector();
        $candidates = [$this->candidate('a', 'a-1'), $this->candidate('b', 'b-1')];
        $seen = [];
        for ($i = 0; $i < 2; ++$i) {
            $seen[] = $selector->select($candidates, 1)[0]->occurrence->taskKey;
        }

        self::assertSame(['a', 'b'], $seen);
    }

    private function candidate(string $task, string $occurrence): ExecutionOccurrenceCandidate
    {
        $mapping = new ExecutionBinding(
            $task,
            $occurrence,
            1,
            new ExecutionIdentity($task, $occurrence, 'attempt', 'run', 'execution'),
            'manifest',
            [],
            [],
            null,
            'run',
            new TimeoutPolicy(1.0),
            false,
            'async',
        );

        return new ExecutionOccurrenceCandidate(new ExecutionOccurrenceKey($task, $occurrence), $mapping, $occurrence);
    }
}
