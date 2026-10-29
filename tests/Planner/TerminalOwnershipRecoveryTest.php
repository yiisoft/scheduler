<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Planner;

use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Sqlite\{Connection, Driver};
use Yiisoft\Scheduler\Integration\Async\Store\SQLiteExecutionLedger;
use Yiisoft\Scheduler\Integration\Async\{ExecutionFence,ExecutionIdentity,ExecutionLedgerQueryInterface,ExecutionLedgerRecord,ExecutionLedgerRecoveryPage,ExecutionLedgerState,ExecutionLaunchDescriptor,ExecutionOccurrenceKey,ExecutionOutcome,ExecutionProcessIdentity,ExecutionRecoveryFence};
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessLookupResult,ProcessScopeProof};
use Yiisoft\Scheduler\Integration\Async\Recovery\{RecoveryAdmissionValidatorInterface,RecoveryClassifier,RecoveryProcessObserverInterface,RecoveryAction};
use Yiisoft\Scheduler\Integration\Async\Process\ProcessIdentity;
use Yiisoft\Scheduler\Integration\Async\ExecutionDispatchReservation;
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchObservation;
use Yiisoft\Scheduler\Integration\Async\ExecutionProcessClosure;
use Yiisoft\Scheduler\Integration\Async\ExecutionStartObservation;

use function assert;

final class TerminalOwnershipRecoveryTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/terminal-recovery-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testTerminalClosedRootPersistsClosureAndReleasesOwnership(): void
    {
        $occurrence = new ExecutionOccurrenceKey('task', 'closed-owner');
        $descriptor = new ExecutionLaunchDescriptor('execution', 1, 'nonce', occurrence: $occurrence);
        $identity = new ExecutionProcessIdentity(7, 7, 7, 'ticks', 'boot');
        $start = new ExecutionStartObservation(1_000_000, 1);
        $record = new ExecutionLedgerRecord($occurrence, new ExecutionIdentity('task', 'closed-owner', 'attempt', 'run', 'execution'), 1, new ExecutionFence(1, 'fence'), ExecutionLedgerState::CLAIMED, 'fingerprint', ['schema' => 'dev', 'source' => ['source', 'revision'], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [1.0, 2.0], 'recoveryMaxAge' => 10.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]], createdAt: 0, dispatchReservation: new ExecutionDispatchReservation('planner', 1, 9, '00000000000000000000000000000007', ExecutionDispatchReservation::OBSERVED), launchObservation: new ExecutionLaunchObservation(7, null, 'started', 1, processIdentity: $identity, startObservation: $start), recoveryFence: new ExecutionRecoveryFence('recovery'), sourceIdentity: 'source', sourceRevision: 'revision', launchDescriptor: $descriptor, processIdentity: $identity, startAnchorIdentity: $identity, startObservation: $start, attemptStartObservation: $start, recoveryAge: 10.0, recoveryDeadline: 11_000_000, claimLeaseDeadline: 30);
        $ledger = $this->ledger();
        $ledger->admit($record);
        self::assertSame('accepted', $ledger->recordOutcome($occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 11)->status);
        assert($record->recoveryFence !== null);
        $closed = $ledger->observeLaunch($occurrence, $record->fence, $record->recoveryFence, new ExecutionLaunchObservation(7, null, 'closed', 12, ExecutionProcessClosure::confirmed(null, 'closed'), processIdentity: $identity, startObservation: $start), 'source');

        self::assertSame('accepted', $closed->status);
        $reopened = $this->ledger();
        self::assertTrue($reopened->observe($occurrence)?->launchObservation?->closure?->isConfirmed());
        self::assertSame([], $reopened->ownershipHoldingExecutions('source'));
    }

    public function testTerminalLedgerOutcomeWinsOverStaleRelaunchAndClosureObservationIsForwarded(): void
    {
        $occurrence = new ExecutionOccurrenceKey('task', 'terminal');
        $descriptor = new ExecutionLaunchDescriptor('execution', 1, 'nonce', occurrence: $occurrence);
        $identity = new ProcessIdentity(7, 7, 7, 'ticks', 'boot', $descriptor);
        $record = new ExecutionLedgerRecord($occurrence, new ExecutionIdentity('task', 'terminal', 'attempt', 'run', 'execution'), 1, new ExecutionFence(1, 'fence'), ExecutionLedgerState::SUCCEEDED, 'fingerprint', ['schema' => 'dev', 'source' => ['source', 'revision'], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [1.0, 2.0], 'recoveryMaxAge' => 10.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]], createdAt: 0, outcome: new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), recoveryFence: new ExecutionRecoveryFence('recovery'), sourceIdentity: 'source', sourceRevision: 'revision', launchDescriptor: $descriptor, processIdentity: new ExecutionProcessIdentity(7, 7, 7, 'ticks', 'boot'));
        $observer = new TerminalOwnershipObserver(new ProcessLookupResult(ProcessLookupResult::CLOSED, proof: ProcessScopeProof::closedEsrchFromLookup($descriptor, $identity)));
        $action = (new RecoveryClassifier(new TerminalOwnershipQuery($record), $observer, new TerminalOwnershipAdmission(false)))->classify('source', 1_000_000, 1)[0];

        self::assertSame(RecoveryAction::TERMINAL, $action->kind);
        self::assertSame(1, $observer->lookups);
        self::assertNotEmpty($action->observations);
        self::assertSame($record->outcome, $action->record->outcome);
    }

    public function testCommandIndependentObservationDoesNotRequireCoordinatorAdmission(): void
    {
        // A terminal ledger fact is authoritative even when the mutable
        // admission gate is closed; this public classifier boundary is the
        // narrow integration seam for command-only/removed-task recovery.
        $occurrence = new ExecutionOccurrenceKey('task', 'removed');
        $record = new ExecutionLedgerRecord($occurrence, new ExecutionIdentity('task', 'removed', 'attempt', 'run', 'execution'), 1, new ExecutionFence(1, 'fence'), ExecutionLedgerState::FAILED, 'fingerprint', ['schema' => 'dev', 'source' => ['source', 'revision'], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [1.0, 2.0], 'recoveryMaxAge' => 10.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]], createdAt: 0, outcome: new ExecutionOutcome(ExecutionOutcome::FAILED), recoveryFence: new ExecutionRecoveryFence('recovery'), sourceIdentity: 'source', sourceRevision: 'revision');
        $action = (new RecoveryClassifier(new TerminalOwnershipQuery($record), new TerminalOwnershipObserver(new ProcessLookupResult(ProcessLookupResult::NO_MATCH)), new TerminalOwnershipAdmission(false)))->classify('source', 1_000_000, 1)[0];
        self::assertSame(RecoveryAction::TERMINAL, $action->kind);
    }

    private function ledger(): SQLiteExecutionLedger
    {
        return new SQLiteExecutionLedger(new Connection(new Driver('sqlite:' . $this->directory . '/ledger.sqlite')));
    }
}
final class TerminalOwnershipQuery implements ExecutionLedgerQueryInterface
{
    public function __construct(private readonly ExecutionLedgerRecord $record) {}

    public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
    {
        return $this->record->occurrence == $occurrence ? $this->record : null;
    }

    public function hasTaskHistory(string $taskKey): bool
    {
        return $this->record->occurrence->taskKey === $taskKey;
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
        return new ExecutionLedgerRecoveryPage($cursor === null ? [$this->record] : [], null);
    }

    public function repairIndexes(): void {}
}
final class TerminalOwnershipAdmission implements RecoveryAdmissionValidatorInterface
{
    public function __construct(private readonly bool $allowed) {}

    public function accepts(ExecutionLedgerRecord $record, string $sourceIdentity): bool
    {
        return $this->allowed;
    }
}
final class TerminalOwnershipObserver implements RecoveryProcessObserverInterface
{
    public int $lookups = 0;

    public function __construct(private readonly ProcessLookupResult $result) {}

    public function lookup(ExecutionLaunchDescriptor $descriptor, ?ExecutionProcessIdentity $expected = null): ProcessLookupResult
    {
        ++$this->lookups;
        return $this->result;
    }
}
