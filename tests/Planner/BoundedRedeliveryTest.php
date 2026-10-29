<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Planner;

use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Sqlite\{Connection, Driver};
use Yiisoft\Scheduler\Integration\Async\Store\SQLiteExecutionLedger;
use Yiisoft\Scheduler\Integration\Async\{ExecutionDispatchReservation,ExecutionFence,ExecutionIdentity,ExecutionLedgerQueryInterface,ExecutionLedgerRecord,ExecutionLedgerRecoveryPage,ExecutionLedgerState,ExecutionLaunchDescriptor,ExecutionLaunchObservation,ExecutionOccurrenceKey,ExecutionOutcome,ExecutionProcessIdentity,ExecutionRecoveryFence,ExecutionStartObservation};
use Yiisoft\Scheduler\Integration\Async\Process\{AttachedProcessHandle,ProcessLookupResult,ProcessScopeProof};
use Yiisoft\Scheduler\Integration\Async\Recovery\{RecoveryAdmissionValidatorInterface,RecoveryAction,RecoveryClassifier,RecoveryProcessObserverInterface};
use Yiisoft\Scheduler\Integration\Async\Process\ProcessIdentity;

use function assert;

final class BoundedRedeliveryTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bounded-recovery-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testDescriptorFreeAsyncRecoveryReachesFreshSameGenerationDescriptorPath(): void
    {
        $record = $this->record(false, 'async', ExecutionLedgerState::LAUNCH_REQUIRED);
        $ledger = $this->ledger();
        $ledger->admit($record);
        $action = (new RecoveryClassifier($ledger, new BoundedRedeliveryObserver(new ProcessLookupResult(ProcessLookupResult::NO_MATCH)), new BoundedRedeliveryAdmission(true)))->classify('source', 2_000_000, 1)[0];
        self::assertSame(RecoveryAction::RELAUNCH, $action->kind);

        $reservation = new ExecutionDispatchReservation('planner', 2, 3, '00000000000000000000000000000002');
        self::assertSame('accepted', $ledger->reserveDispatch($record->occurrence, $record->fence, $reservation)->status);
        $descriptor = new ExecutionLaunchDescriptor('execution', 1, 'fresh-nonce', occurrence: $record->occurrence);
        assert($record->recoveryFence !== null);
        self::assertSame('accepted', $ledger->recordLaunchDescriptor($record->occurrence, $record->fence, $record->recoveryFence, $descriptor, 'source')->status);
        self::assertEquals($descriptor, ($this->ledger())->observe($record->occurrence)?->launchDescriptor);
    }

    public function testExactSecondRecoveryWindowAcceptsExclusiveReservationUntilDeadline(): void
    {
        $record = $this->record(false, 'async', ExecutionLedgerState::LAUNCH_REQUIRED, started: true);
        $ledger = $this->ledger();
        $ledger->admit($record);

        $reservation = new ExecutionDispatchReservation('planner', 2, 3, '00000000000000000000000000000003');
        $result = $ledger->reserveDispatch($record->occurrence, $record->fence, $reservation);

        self::assertSame('accepted', $result->status);
        self::assertSame(3, $result->record?->dispatchReservation?->deadline);
        self::assertSame(3_000_000, $record->recoveryDeadline);
    }

    public function testExactSecondRecoveryDeadlineRefusesRedeliveryAtBoundary(): void
    {
        $record = $this->record();
        assert($record->launchDescriptor !== null);
        $identity = new ProcessIdentity(1, 1, 1, 'ticks', 'boot', $record->launchDescriptor);
        $observer = new BoundedRedeliveryObserver(new ProcessLookupResult(
            ProcessLookupResult::CLOSED,
            proof: ProcessScopeProof::closedEsrchFromLookup($record->launchDescriptor, $identity),
        ));
        $action = (new RecoveryClassifier(
            new BoundedRedeliveryQuery($record),
            $observer,
            new BoundedRedeliveryAdmission(true),
        ))->classify('source', 3_000_000, 1)[0];

        self::assertNotSame(RecoveryAction::AGGREGATE_REDELIVERY, $action->kind);
    }

    public function testClosedAggregateProducesOneBoundedRedeliveryActionWithOriginalDeadline(): void
    {
        $record = $this->record();
        assert($record->launchDescriptor !== null);
        $identity = new ProcessIdentity(1, 1, 1, 'ticks', 'boot', $record->launchDescriptor);
        $observer = new BoundedRedeliveryObserver(new ProcessLookupResult(ProcessLookupResult::CLOSED, proof: ProcessScopeProof::closedEsrchFromLookup($record->launchDescriptor, $identity)));
        $action = (new RecoveryClassifier(new BoundedRedeliveryQuery($record), $observer, new BoundedRedeliveryAdmission(true)))->classify('source', 2_000_000, 1)[0];

        self::assertSame(RecoveryAction::AGGREGATE_REDELIVERY, $action->kind);
        self::assertSame(3_000_000, $action->deadlineUs);
        self::assertCount(1, $action->observations);
        self::assertSame(1, $observer->lookups);
    }

    public function testDescriptorFreeSynchronousRecoveryNeverRelaunches(): void
    {
        $record = $this->record(false, 'sync', ExecutionLedgerState::LAUNCH_REQUIRED);
        $ledger = $this->ledger();
        $ledger->admit($record);
        $action = (new RecoveryClassifier($ledger, new BoundedRedeliveryObserver(new ProcessLookupResult(ProcessLookupResult::NO_MATCH)), new BoundedRedeliveryAdmission(true)))->classify('source', 2_000_000, 1)[0];

        self::assertSame(RecoveryAction::BLOCKED, $action->kind);
        self::assertNull(($this->ledger())->observe($record->occurrence)?->launchDescriptor);
    }

    /** @dataProvider noRotationCases */
    public function testTerminalLiveUncertainAndExpiredCasesNeverAuthorizeRotation(string $lookup, int $now, bool $terminal = false): void
    {
        $record = $this->record($terminal);
        assert($record->launchDescriptor !== null);
        $identity = new ProcessIdentity(1, 1, 1, 'ticks', 'boot', $record->launchDescriptor);
        $observer = new BoundedRedeliveryObserver($lookup === ProcessLookupResult::MATCH
            ? new ProcessLookupResult($lookup, $identity, new AttachedProcessHandle($identity), ProcessScopeProof::liveFromLookup($record->launchDescriptor, $identity))
            : new ProcessLookupResult($lookup));
        $action = (new RecoveryClassifier(new BoundedRedeliveryQuery($record), $observer, new BoundedRedeliveryAdmission(true)))->classify('source', $now, 1)[0];
        self::assertNotSame(RecoveryAction::AGGREGATE_REDELIVERY, $action->kind);
        self::assertNotSame(RecoveryAction::RELAUNCH, $action->kind);
    }

    public static function noRotationCases(): array
    {
        return [[ProcessLookupResult::MATCH, 2_000_000], [ProcessLookupResult::BLOCKED, 2_000_000], [ProcessLookupResult::NO_MATCH, 3_000_000], [ProcessLookupResult::NO_MATCH, 2_000_000, true]];
    }

    public function testStaleRecoveryFenceIsFencedBeforeAnyRedelivery(): void
    {
        $record = $this->record();
        assert($record->launchDescriptor !== null);
        $record = $record->transition(recoveryFence: new ExecutionRecoveryFence('stale'));
        $action = (new RecoveryClassifier(new BoundedRedeliveryQuery($record), new BoundedRedeliveryObserver(new ProcessLookupResult(ProcessLookupResult::NO_MATCH)), new BoundedRedeliveryAdmission(true)))->classify('source', 2_000_000, 1)[0];
        self::assertSame(RecoveryAction::BLOCKED, $action->kind);
    }

    private function ledger(): SQLiteExecutionLedger
    {
        return new SQLiteExecutionLedger(new Connection(new Driver('sqlite:' . $this->directory . '/ledger.sqlite')));
    }

    private function record(bool $terminal = false, string $mode = 'async', string $state = ExecutionLedgerState::RUNNING, bool $started = false): ExecutionLedgerRecord
    {
        $occurrence = new ExecutionOccurrenceKey('task', 'redelivery');
        $asyncStarted = $mode === 'async' && $state !== ExecutionLedgerState::LAUNCH_REQUIRED;
        $hasStart = $asyncStarted || $started;
        $descriptor = $asyncStarted ? new ExecutionLaunchDescriptor('execution', 1, 'nonce', occurrence: $occurrence) : null;
        $identity = $hasStart ? new ExecutionProcessIdentity(1, 1, 1, 'ticks', 'boot') : null;
        $state = $terminal ? ExecutionLedgerState::SUCCEEDED : $state;
        $start = $hasStart ? new ExecutionStartObservation(1_000_000, 1) : null;
        return new ExecutionLedgerRecord($occurrence, new ExecutionIdentity('task', 'redelivery', 'attempt', 'run', 'execution'), 1, new ExecutionFence(1, 'fence'), $state, 'fingerprint', ['schema' => 'dev', 'source' => ['source', 'revision'], 'task' => ['id' => 'task', 'mode' => $mode, 'timeout' => $mode === 'async' ? [1.0, 2.0] : null, 'recoveryMaxAge' => $mode === 'async' ? 2.0 : null, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]], createdAt: 0, outcome: $terminal ? new ExecutionOutcome(ExecutionOutcome::SUCCEEDED) : null, dispatchReservation: $asyncStarted ? new ExecutionDispatchReservation('planner', 1, 9, '00000000000000000000000000000001', ExecutionDispatchReservation::OBSERVED) : null, launchObservation: $asyncStarted ? new ExecutionLaunchObservation(1, null, 'started', 1, processIdentity: $identity, startObservation: $start) : null, recoveryFence: new ExecutionRecoveryFence('recovery'), sourceIdentity: 'source', sourceRevision: 'revision', launchDescriptor: $descriptor, processIdentity: $asyncStarted ? $identity : null, startAnchorIdentity: $hasStart ? $identity : null, startObservation: $start, attemptStartObservation: $asyncStarted ? $start : null, recoveryAge: $hasStart ? 2.0 : null, recoveryDeadline: $hasStart ? 3_000_000 : null);
    }
}
final class BoundedRedeliveryQuery implements ExecutionLedgerQueryInterface
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
final class BoundedRedeliveryAdmission implements RecoveryAdmissionValidatorInterface
{
    public function __construct(private readonly bool $allowed) {}

    public function accepts(ExecutionLedgerRecord $record, string $sourceIdentity): bool
    {
        return $this->allowed;
    }
}
final class BoundedRedeliveryObserver implements RecoveryProcessObserverInterface
{
    public int $lookups = 0;

    public function __construct(private readonly ProcessLookupResult $result) {}

    public function lookup(ExecutionLaunchDescriptor $descriptor, ?ExecutionProcessIdentity $expected = null): ProcessLookupResult
    {
        ++$this->lookups;
        return $this->result;
    }
}
