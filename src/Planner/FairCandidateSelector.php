<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use Yiisoft\Scheduler\Integration\Async\ExecutionOccurrenceCandidate;
use InvalidArgumentException;

use function count;

use const SORT_STRING;

/** Deterministic round-robin selection; input is never acknowledged or consumed. */
final class FairCandidateSelector
{
    private ?string $lastTask = null;

    /** @param list<ExecutionOccurrenceCandidate> $candidates @return list<ExecutionOccurrenceCandidate> */
    public function select(array $candidates, int $limit): array
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('Selection limit cannot be negative.');
        }
        $groups = [];
        foreach ($candidates as $candidate) {
            $groups[$candidate->occurrence->taskKey][] = $candidate;
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as &$group) {
            usort($group, static fn(ExecutionOccurrenceCandidate $a, ExecutionOccurrenceCandidate $b): int => strcmp($a->occurrence->occurrenceId, $b->occurrence->occurrenceId));
        }
        unset($group);

        $tasks = array_keys($groups);
        $selected = [];
        $start = $this->startingTaskIndex($tasks);
        while (count($selected) < $limit && $tasks !== []) {
            $added = false;
            $roundStart = $start;
            for ($offset = 0; $offset < count($tasks); ++$offset) {
                $index = ($roundStart + $offset) % count($tasks);
                $task = $tasks[$index];
                if (!isset($groups[$task][0])) {
                    continue;
                }
                $candidate = array_shift($groups[$task]);
                if (!$candidate instanceof ExecutionOccurrenceCandidate) {
                    throw new InvalidArgumentException('Candidate groups contain an invalid occurrence candidate.');
                }
                $selected[] = $candidate;
                $this->lastTask = $task;
                $start = ($index + 1) % count($tasks);
                $added = true;
                if (count($selected) >= $limit) {
                    break;
                }
            }
            if (!$added) {
                break;
            }
        }
        return $selected;
    }

    /** @param list<string> $tasks */
    private function startingTaskIndex(array $tasks): int
    {
        if ($this->lastTask === null || $tasks === []) {
            return 0;
        }
        foreach ($tasks as $index => $task) {
            if (strcmp($task, $this->lastTask) > 0) {
                return $index;
            }
        }
        return 0;
    }
}
