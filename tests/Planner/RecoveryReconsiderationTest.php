<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Planner;

use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\{ExecutionFence,ExecutionIdentity,ExecutionLedgerQueryInterface,ExecutionLedgerRecord,ExecutionLedgerRecoveryPage,ExecutionLedgerState,ExecutionLaunchDescriptor,ExecutionOccurrenceKey,ExecutionRecoveryFence,ExecutionStartObservation};
use Yiisoft\Scheduler\Integration\Async\Process\{AttachedProcessHandle,ProcessLookupResult,ProcessScopeProof};
use Yiisoft\Scheduler\Integration\Async\Recovery\{RecoveryAdmissionValidatorInterface,RecoveryClassifier,RecoveryProcessObserverInterface};
use Yiisoft\Scheduler\Integration\Async\{ExecutionProcessIdentity};
use Yiisoft\Scheduler\Integration\Async\Process\ProcessIdentity;

use function array_slice;
use function count;

/** Regression coverage for the bounded, observation-first planner recovery scan. */
final class RecoveryReconsiderationTest extends TestCase
{
    public function testRecordsBeyondOnePageAreEventuallyConsideredAndCursorWraps(): void
    {
        $records = array_map(fn(int $i): ExecutionLedgerRecord => $this->record('o' . $i), range(1, 260));
        $observer = new RecoveryReconsiderationObserver(new ProcessLookupResult(ProcessLookupResult::NO_MATCH));
        $query = new RecoveryReconsiderationQuery($records);
        $classifier = new RecoveryClassifier($query, $observer, new RecoveryReconsiderationAdmission(true));

        $actions = $classifier->classify('source', 1_000_000, count($records));

        self::assertCount(260, $actions);
        self::assertSame(260, $observer->lookups);
        self::assertContains(256, $query->limits);
        self::assertContains(4, $query->limits);
    }

    public function testBlockedUncertainObservationCanBeRetriedAfterClockAdvancementWithoutTotalCap(): void
    {
        $record = $this->record('retry', new ExecutionStartObservation(1_000_000, 1), 10.0);
        $observer = new RecoveryReconsiderationObserver(new ProcessLookupResult(ProcessLookupResult::BLOCKED));
        $classifier = new RecoveryClassifier(new RecoveryReconsiderationQuery([$record]), $observer, new RecoveryReconsiderationAdmission(true));

        for ($i = 0; $i < 40; ++$i) {
            self::assertSame('blocked/unresolved', $classifier->classify('source', 1_100_000 + $i, 1)[0]->kind);
        }
        $observer->result = new ProcessLookupResult(ProcessLookupResult::NO_MATCH);
        self::assertSame('blocked/unresolved', $classifier->classify('source', 2_000_000, 1)[0]->kind);
        self::assertSame('expired/unresolved', $classifier->classify('source', 11_000_000, 1)[0]->kind);
    }

    public function testSourceAdmissionMismatchStillForwardsRecoveryObservationAndExpiredLiveScopeIsObserved(): void
    {
        $record = $this->record('live', new ExecutionStartObservation(1_000_000, 1), 1.0);
        $identity = new ProcessIdentity(1, 1, 1, 'ticks', 'boot', $record->launchDescriptor);
        $observer = new RecoveryReconsiderationObserver(new ProcessLookupResult(
            ProcessLookupResult::MATCH,
            $identity,
            handle: new AttachedProcessHandle($identity),
            proof: ProcessScopeProof::liveFromLookup($record->launchDescriptor, $identity),
        ));
        $classifier = new RecoveryClassifier(new RecoveryReconsiderationQuery([$record]), $observer, new RecoveryReconsiderationAdmission(false));

        $action = $classifier->classify('source', 2_000_000, 1)[0];
        self::assertSame('unresolved/wait-observe', $action->kind);
        self::assertSame(1, $observer->lookups);
        self::assertNotNull($action->lookup);
        self::assertSame(1, count($action->observations));
    }

    private function record(string $id, ?ExecutionStartObservation $start = null, ?float $age = null): ExecutionLedgerRecord
    {
        $occurrence = new ExecutionOccurrenceKey('task', $id);
        $descriptor = new ExecutionLaunchDescriptor('execution-' . $id, 1, 'nonce-' . $id, occurrence: $occurrence);
        $identity = new ExecutionProcessIdentity(1, 1, 1, 'ticks', 'boot');
        return new ExecutionLedgerRecord($occurrence, new ExecutionIdentity('task', $id, 'attempt', 'run', 'execution-' . $id), 1, new ExecutionFence(1, 'fence-' . $id), ExecutionLedgerState::RUNNING, 'fingerprint', ['schema' => 'dev', 'source' => ['source', 'revision'], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [1.0, 2.0], 'recoveryMaxAge' => $age ?? 10.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]], createdAt: 0, recoveryFence: new ExecutionRecoveryFence('recovery-' . $id), sourceIdentity: 'source', sourceRevision: 'revision', launchDescriptor: $descriptor, processIdentity: $start === null ? $identity : $identity, startAnchorIdentity: $start === null ? null : $identity, startObservation: $start, attemptStartObservation: $start, recoveryAge: $start === null ? null : ($age ?? 10.0), recoveryDeadline: $start === null ? null : $start->wallMicroseconds + (int) (max($age ?? 10.0, 2.0) * 1_000_000));
    }
}

final class RecoveryReconsiderationQuery implements ExecutionLedgerQueryInterface
{
    public array $limits = [];

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
        return [];
    }

    public function ownershipHoldingExecutions(string $sourceIdentity): array
    {
        return [];
    }

    public function recoveryPage(string $sourceIdentity, ?string $cursor, int $limit): ExecutionLedgerRecoveryPage
    {
        $this->limits[] = $limit;
        $offset = $cursor === null ? 0 : (int) $cursor;
        $page = array_slice($this->records, $offset, $limit);
        $next = $offset + count($page) < count($this->records) ? (string) ($offset + count($page)) : null;
        return new ExecutionLedgerRecoveryPage($page, $next);
    }

    public function repairIndexes(): void {}
}
final class RecoveryReconsiderationAdmission implements RecoveryAdmissionValidatorInterface
{
    public function __construct(private readonly bool $allowed) {}

    public function accepts(ExecutionLedgerRecord $record, string $sourceIdentity): bool
    {
        return $this->allowed;
    }
}
final class RecoveryReconsiderationObserver implements RecoveryProcessObserverInterface
{
    public int $lookups = 0;

    public function __construct(public ProcessLookupResult $result) {}

    public function lookup(ExecutionLaunchDescriptor $descriptor, ?ExecutionProcessIdentity $expected = null): ProcessLookupResult
    {
        ++$this->lookups;
        return $this->result;
    }
}
