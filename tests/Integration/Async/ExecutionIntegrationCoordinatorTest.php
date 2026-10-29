<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async;

use Yiisoft\Scheduler\Tests\Support\SQLiteLedgerFixture;
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchDescriptor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use LogicException;
use Throwable;
use Yiisoft\Scheduler\Config\{PreparedConfiguration, TimeoutPolicy};
use Yiisoft\Scheduler\Integration\Async\{
    ExecutionIdentity, ExecutionBinding, ExecutionAdmission, ExecutionClaimResult, ExecutionCommandScope,
    ExecutionDispatchReservation, ExecutionDispatchResult, ExecutionFence, ExecutionGenerationResult,
    ExecutionIntegrationCoordinator, ExecutionLaunchObservation, ExecutionProcessClosure, ExecutionLedgerCommandInterface, ExecutionLedgerQueryInterface, ExecutionLedgerRecord, ExecutionLedgerRecoveryPage,
    ExecutionOccurrenceCandidate, ExecutionOccurrenceKey, ExecutionOccurrenceSourceInterface, OccurrenceSkipReason, ExecutionOutcome,
    ExecutionRecoveryFence, ExecutionRecoveryResult, ExecutionRevocationResult, ExecutionTerminalResult, ExecutionLedgerState,
    ExecutionWorkerCapability, ExecutionWorkerLaunchHandle, ExecutionWorkerLaunchReceipt, ExecutionWorkerLaunchRequest, ExecutionWorkerLauncherInterface, WorkerLifecyclePolicy,
};
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessHandleInterface, ProcessLaunchResult, ProcessIdentity, ProcessResult, ProcessScopeProof};
use PHPUnit\Framework\Attributes\DataProvider;

final class ExecutionIntegrationCoordinatorTest extends TestCase
{
    public function testNoCandidateDoesNotAdmitOrLaunch(): void
    {
        $ledger = new CoordinatorTestLedger($this->record());
        $launcher = new CoordinatorTestLauncher();
        $result = $this->coordinator($ledger, new CoordinatorTestSource(null), $launcher)
            ->dispatchNext($this->record(), $this->capability(), $this->reservation());
        self::assertFalse($result->hasWork());
        self::assertSame(0, $ledger->admissions);
        self::assertFalse($launcher->called);
    }

    public function testAdmissionExceptionLeavesCursorUnchangedAndDoesNotAcknowledge(): void
    {
        $source = new CoordinatorTestSource($this->candidate());
        $ledger = new CoordinatorTestLedger($this->record(), throwOnAdmission: true);

        try {
            $this->coordinator($ledger, $source)->dispatchNext($this->record(), $this->capability(), $this->reservation());
            self::fail('Admission should fail.');
        } catch (LogicException) {
            self::assertSame(0, $source->acknowledgements);
            self::assertSame('cursor-1', $source->next()->cursor);
        }
    }

    public function testMismatchedCandidateIsRejectedBeforeAdmission(): void
    {
        $source = new CoordinatorTestSource($this->candidate('cursor-2'));
        $ledger = new CoordinatorTestLedger($this->record());

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->coordinator($ledger, $source)->dispatchNext($this->record(), $this->capability(), $this->reservation());
        } finally {
            self::assertSame(0, $ledger->admissions);
            self::assertSame(0, $source->acknowledgements);
        }
    }

    public function testMismatchedReceiptIdentityRemainsAmbiguous(): void
    {
        $launcher = new CoordinatorTestLauncher();
        $launcher->mismatchedIdentity = true;
        $ledger = new CoordinatorTestLedger($this->record(), dispatch: ExecutionDispatchResult::accepted($this->record()));

        $result = $this->coordinator($ledger, new CoordinatorTestSource($this->candidate()), $launcher)
            ->dispatchNext($this->record(), $this->capability(), $this->reservation());

        self::assertSame(ExecutionDispatchResult::AMBIGUOUS, $result->dispatch?->status);
    }

    public function testUnenforcedAsyncLaunchPreservesNullLifecyclePolicy(): void
    {
        $launcher = new CoordinatorTestLauncher();
        $ledger = new CoordinatorTestLedger($this->record(), dispatch: ExecutionDispatchResult::accepted($this->record()));

        $this->coordinator($ledger, new CoordinatorTestSource($this->candidate()), $launcher)
            ->dispatchNext($this->record(), $this->capability(), $this->reservation());

        self::assertNotNull($launcher->request);
        self::assertNull($launcher->request->lifecyclePolicy);
    }

    public function testConfiguredAsyncLaunchPreservesLifecyclePolicy(): void
    {
        $policy = new WorkerLifecyclePolicy(1.5, 3.0);
        $launcher = new CoordinatorTestLauncher();
        $ledger = new CoordinatorTestLedger($this->record(), dispatch: ExecutionDispatchResult::accepted($this->record()));

        $this->coordinator($ledger, new CoordinatorTestSource($this->candidate()), $launcher)
            ->dispatchNext($this->record(), $this->capability(), $this->reservation(), $policy);

        self::assertSame($policy, $launcher->request?->lifecyclePolicy);
    }

    public function testNewAdmissionAcknowledgesBeforeReservationAndLaunch(): void
    {
        $events = [];
        $source = new CoordinatorTestSource($this->candidate(), $events);
        $ledger = new CoordinatorTestLedger($this->record(), events: $events, dispatch: ExecutionDispatchResult::accepted($this->record()));
        $launcher = new CoordinatorTestLauncher($events);

        $result = $this->coordinator($ledger, $source, $launcher)->dispatchNext($this->record(), $this->capability(), $this->reservation());

        self::assertNotNull($result->launchHandle);
        self::assertSame(['ack', 'reserve', 'launch'], $events);
        self::assertSame(1, $source->acknowledgements);
    }

    public function testExistingAdmissionAcknowledgesTheExactCandidateOnce(): void
    {
        $candidate = $this->candidate();
        $source = new CoordinatorTestSource($candidate);
        $ledger = new CoordinatorTestLedger($this->record(), newlyAdmitted: false, dispatch: ExecutionDispatchResult::alreadyLaunched($this->record()));

        $this->coordinator($ledger, $source)->dispatchNext($this->record(), $this->capability(), $this->reservation());

        self::assertSame(1, $source->acknowledgements);
        self::assertSame($candidate->cursor, $source->acknowledgedCandidate->cursor);
        self::assertSame($candidate->occurrence->occurrenceId, $source->acknowledgedCandidate->occurrence->occurrenceId);
        self::assertNull($source->next());
    }

    #[DataProvider('reservationGates')]
    public function testReservationGateDoesNotLaunch(string $status): void
    {
        $launcher = new CoordinatorTestLauncher();
        $dispatch = match ($status) {
            ExecutionDispatchResult::ALREADY_LAUNCHED => ExecutionDispatchResult::alreadyLaunched($this->record()),
            ExecutionDispatchResult::STALE => ExecutionDispatchResult::stale(),
            default => ExecutionDispatchResult::rejected('rejected'),
        };
        $ledger = new CoordinatorTestLedger($this->record(), dispatch: $dispatch);
        $result = $this->coordinator($ledger, new CoordinatorTestSource($this->candidate()), $launcher)
            ->dispatchNext($this->record(), $this->capability(), $this->reservation());

        self::assertNull($result->launchHandle);
        self::assertFalse($launcher->called);
    }

    public static function reservationGates(): array
    {
        return [
            'already launched' => [ExecutionDispatchResult::ALREADY_LAUNCHED],
            'stale' => [ExecutionDispatchResult::STALE],
            'rejected' => [ExecutionDispatchResult::REJECTED],
        ];
    }

    public function testCapabilityMismatchPreventsAdmissionAcknowledgementAndLaunch(): void
    {
        $launcher = new CoordinatorTestLauncher();
        $ledger = new CoordinatorTestLedger($this->record());
        $source = new CoordinatorTestSource($this->candidate());
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->coordinator($ledger, $source, $launcher)
                ->dispatchNext($this->record(), new ExecutionWorkerCapability($this->occurrence(), 'wrong', 'run', 'execution', 1, 'fence', 'manifest', 'v1', 'ledger', 'runtime', 'source-revision', ['schema' => 'dev', 'source' => ['source', 'source-revision'], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => null, 'recoveryMaxAge' => 180.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]]), $this->reservation());
        } finally {
            self::assertSame(0, $ledger->admissions);
            self::assertSame(0, $source->acknowledgements);
            self::assertFalse($launcher->called);
        }
    }

    public function testRestartAfterAcknowledgementBeforeReservationRedispatchesExactlyOnce(): void
    {
        $directory = sys_get_temp_dir() . '/schedule-coordinator-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        try {
            $record = $this->record();
            $source = new CoordinatorTestSource($this->candidate());
            $launcher = new CoordinatorTestLauncher();
            $ledger = new CoordinatorFaultLedger(SQLiteLedgerFixture::ledger($directory));

            try {
                $this->coordinator($ledger, $source, $launcher)->dispatchNext($record, $this->capability(), $this->reservation());
                self::fail('The simulated crash should interrupt dispatch before reservation.');
            } catch (LogicException $e) {
                self::assertSame('simulated crash before reserveDispatch', $e->getMessage());
            }

            self::assertSame(1, $source->acknowledgements);
            self::assertFalse($launcher->called);
            self::assertSame(ExecutionLedgerState::LAUNCH_REQUIRED, (SQLiteLedgerFixture::ledger($directory))->observe($record->occurrence)?->state);

            $reopened = SQLiteLedgerFixture::ledger($directory);
            $recovery = $reopened->recover($record->occurrence, new ExecutionRecoveryFence('recovery', 1), 10_000_000, $record->fence, 'source');
            self::assertSame(ExecutionRecoveryResult::SAME_GENERATION_UNCLAIMED, $recovery->status);
            self::assertNotNull($recovery->record);

            $restartLauncher = new CoordinatorTestLauncher();
            $restartSource = new CoordinatorTestSource(null);
            $restart = $this->coordinator($reopened, $restartSource, $restartLauncher)
                ->redispatchSameGeneration($recovery, $this->capability(), new ExecutionDispatchReservation('restart', 11, 20), 11_000_000);

            self::assertNotNull($restart->launchHandle);
            self::assertSame(1, $restartLauncher->launches);
            self::assertSame(0, $restartSource->acknowledgements);
            self::assertEquals($record->occurrence, $restart->dispatch?->record?->occurrence);
            self::assertEquals($record->correlation, $restart->dispatch?->record?->correlation);
            self::assertSame($record->attempt, $restart->dispatch?->record?->attempt);
            self::assertEquals($record->fence, $restart->dispatch?->record?->fence);
            self::assertEquals($record->recoveryFence, $restart->dispatch?->record?->recoveryFence);
            // Redispatch publishes the complete launch receipt while holding the
            // occurrence lock, rather than leaving an unpublished launch request.
            self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, (SQLiteLedgerFixture::ledger($directory))->observe($record->occurrence)?->state);
            self::assertSame('restart', (SQLiteLedgerFixture::ledger($directory))->observe($record->occurrence)?->dispatchReservation?->dispatcherId);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function testSameGenerationRetryLaunchesOnlyForUnclaimedStatus(): void
    {
        $launcher = new CoordinatorTestLauncher();
        $ledger = new CoordinatorTestLedger($this->record(), redispatch: ExecutionDispatchResult::sameGenerationUnclaimed($this->record()));
        $result = $this->coordinator($ledger, new CoordinatorTestSource(null), $launcher)
            ->redispatchSameGeneration(ExecutionRecoveryResult::sameGenerationUnclaimed($this->record()), $this->capability(), $this->reservation(), 10_000_000);

        self::assertNotNull($result->launchHandle);
        self::assertSame(0, $ledger->generationAllocations);

        $launcher = new CoordinatorTestLauncher();
        $ledger = new CoordinatorTestLedger($this->record(), redispatch: ExecutionDispatchResult::accepted($this->record()));
        $result = $this->coordinator($ledger, new CoordinatorTestSource(null), $launcher)
            ->redispatchSameGeneration(ExecutionRecoveryResult::sameGenerationUnclaimed($this->record()), $this->capability(), $this->reservation(), 10_000_000);
        self::assertNull($result->launchHandle);
        self::assertFalse($launcher->called);
    }

    public function testAmbiguityPreservesGenerationAndDoesNotRedispatch(): void
    {
        $ledger = new CoordinatorTestLedger($this->record());
        $this->expectException(InvalidArgumentException::class);
        try {
            $this->coordinator($ledger, new CoordinatorTestSource(null))->redispatchSameGeneration(
                ExecutionRecoveryResult::ambiguous($this->record()),
                $this->capability(),
                $this->reservation(),
                10,
            );
        } finally {
            self::assertSame(1, $this->record()->fence->generation);
            self::assertSame(0, $ledger->redispatches);
            self::assertSame(0, $ledger->generationAllocations);
        }
    }

    public function testConfirmedTerminalClosureIsForwardedForDurableRelease(): void
    {
        $ledger = new CoordinatorTestLedger($this->record('succeeded'));
        $result = $this->coordinator($ledger, new CoordinatorTestSource(null))->observeLaunch(
            $this->occurrence(),
            new ExecutionFence(1, 'fence'),
            new ExecutionLaunchObservation(null, null, 'closed', 2, ExecutionProcessClosure::confirmed(0, 'closed')),
        );

        self::assertSame(ExecutionDispatchResult::ACCEPTED, $result->status);
        self::assertSame(1, $ledger->launchObservations);
    }

    public function testTerminalObservationIsAuthoritative(): void
    {
        $ledger = new CoordinatorTestLedger($this->record('succeeded'));
        $result = $this->coordinator($ledger, new CoordinatorTestSource(null))->observeLaunch(
            $this->occurrence(),
            new ExecutionFence(1, 'fence'),
            new ExecutionLaunchObservation(null, null, 'unknown', 2),
        );
        self::assertSame(ExecutionDispatchResult::ACCEPTED, $result->status);
        self::assertSame(1, $ledger->observations);
        self::assertSame(0, $ledger->launchObservations);
    }

    public function testWrongFenceNonConfirmedTerminalObservationIsNotAccepted(): void
    {
        $ledger = new CoordinatorTestLedger($this->record('succeeded'));
        $result = $this->coordinator($ledger, new CoordinatorTestSource(null))->observeLaunch(
            $this->occurrence(),
            new ExecutionFence(1, 'wrong-fence'),
            new ExecutionLaunchObservation(null, null, 'unknown', 2),
        );

        self::assertSame(ExecutionDispatchResult::STALE, $result->status);
        self::assertSame(1, $ledger->observations);
        self::assertSame(0, $ledger->launchObservations);
    }

    private function coordinator(ExecutionLedgerCommandInterface $ledger, CoordinatorTestSource $source, ?CoordinatorTestLauncher $launcher = null): ExecutionIntegrationCoordinator
    {
        return new ExecutionIntegrationCoordinator($ledger, $source, $launcher ?? new CoordinatorTestLauncher(), $ledger, 'source');
    }

    private function occurrence(): ExecutionOccurrenceKey
    {
        return new ExecutionOccurrenceKey('task', 'occurrence');
    }

    private function reservation(): ExecutionDispatchReservation
    {
        return new ExecutionDispatchReservation('d', 1, 2, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    }

    private function candidate(string $cursor = 'cursor-1'): ExecutionOccurrenceCandidate
    {
        $mapping = new ExecutionBinding(
            'task',
            'occurrence',
            1,
            new ExecutionIdentity('task', 'occurrence', 'attempt', 'run', 'execution'),
            'manifest',
            ['schema' => 'dev', 'source' => ['source', 'source-revision'], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [1.0, null], 'recoveryMaxAge' => 180.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]],
            [],
            null,
            'run',
            new TimeoutPolicy(1.0),
            false,
            'async',
        );
        return new ExecutionOccurrenceCandidate($this->occurrence(), $mapping, $cursor);
    }

    private function capability(): ExecutionWorkerCapability
    {
        $policy = ['schema' => 'dev', 'source' => ['source', 'source-revision'], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [1.0, null], 'recoveryMaxAge' => 180.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]];
        return new ExecutionWorkerCapability($this->occurrence(), 'attempt', 'run', 'execution', 1, 'fence', 'manifest', hash('sha256', PreparedConfiguration::canonical($policy)), 'ledger', 'runtime', 'source-revision', $policy, new ExecutionRecoveryFence('recovery', 1), new ExecutionLaunchDescriptor('execution', 1, 'worker-nonce', occurrence: $this->occurrence()), 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    }

    private function record(string $state = 'launch-required'): ExecutionLedgerRecord
    {
        $candidate = $this->candidate();
        return new ExecutionLedgerRecord($this->occurrence(), new ExecutionIdentity('task', 'occurrence', 'attempt', 'run', 'execution'), 1, new ExecutionFence(1, 'fence'), $state, 'manifest', ['schema' => 'dev', 'source' => ['source', 'source-revision'], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [1.0, null], 'recoveryMaxAge' => 180.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]], 1, 1, $state === 'succeeded' ? new ExecutionOutcome('succeeded') : null, ['occurrenceCursor' => $candidate->cursor, 'taskMappingIdentity' => $candidate->taskMappingIdentity()], dispatchReservation: $this->reservation(), recoveryFence: new ExecutionRecoveryFence('recovery'), sourceIdentity: 'source', sourceRevision: 'source-revision', launchDescriptor: $this->capability()->launchDescriptor);
    }
}

final class CoordinatorTestSource implements ExecutionOccurrenceSourceInterface
{
    public int $acknowledgements = 0;
    public ?ExecutionOccurrenceCandidate $acknowledgedCandidate = null;
    private array $events;

    public function __construct(private ?ExecutionOccurrenceCandidate $candidate, array &$events = [])
    {
        $this->events = & $events;
    }

    public function next(): ?ExecutionOccurrenceCandidate
    {
        return $this->candidate;
    }

    public function acknowledge(ExecutionOccurrenceCandidate $candidate, ExecutionAdmission $admission): void
    {
        ++$this->acknowledgements;
        $this->acknowledgedCandidate = $candidate;
        $this->candidate = null;
        $this->events[] = 'ack';
    }

    public function skip(ExecutionOccurrenceCandidate $candidate, OccurrenceSkipReason $reason): void
    {
        if ($this->candidate === null || !$this->candidate->isSameCandidate($candidate)) {
            throw new InvalidArgumentException('Candidate skip does not match the current candidate.');
        }
        $this->candidate = null;
        $this->events[] = 'skip';
    }
}

final class CoordinatorTestLauncher implements ExecutionWorkerLauncherInterface
{
    public bool $called = false;
    public bool $mismatchedIdentity = false;
    public int $launches = 0;
    public ?ExecutionWorkerLaunchRequest $request = null;
    private array $events;

    public function __construct(array &$events = [])
    {
        $this->events = & $events;
    }

    public function launch(ExecutionWorkerLaunchRequest $request): ExecutionWorkerLaunchReceipt
    {
        $this->called = true;
        $this->request = $request;
        ++$this->launches;
        $descriptor = $request->descriptor;
        if ($this->mismatchedIdentity) {
            $descriptor = new ExecutionLaunchDescriptor($descriptor->executionId, $descriptor->generation, 'mismatched');
        }
        $process = new CoordinatorTestProcessHandle();
        return new ExecutionWorkerLaunchReceipt(
            new ExecutionWorkerLaunchHandle('handle'),
            new ProcessLaunchResult($process, 1.0, 1000000, new ProcessIdentity(10, 10, 10, '1', 'boot', $descriptor)),
        );
    }
}

final class CoordinatorTestProcessHandle implements ProcessHandleInterface
{
    public function scopeProof(): ProcessScopeProof
    {
        throw new LogicException();
    }

    public function isRunning(): bool
    {
        return true;
    }

    public function isQuiescent(): bool
    {
        return false;
    }

    public function poll(): ?ProcessResult
    {
        return null;
    }

    public function terminateSoft(): void {}

    public function terminateHard(): void {}
}

final class CoordinatorTestLedger implements ExecutionLedgerCommandInterface, ExecutionLedgerQueryInterface
{
    public int $admissions = 0;
    public int $observations = 0;
    public int $launchObservations = 0;
    public int $redispatches = 0;
    public int $generationAllocations = 0;
    private array $events;

    public function __construct(private readonly ExecutionLedgerRecord $admitted, private readonly bool $throwOnAdmission = false, private readonly bool $newlyAdmitted = true, array &$events = [], private readonly ?ExecutionDispatchResult $dispatch = null, private readonly ?ExecutionDispatchResult $redispatch = null)
    {
        $this->events = & $events;
    }

    public function repairIndexes(): void {}

    public function admit(ExecutionLedgerRecord $record): ExecutionAdmission
    {
        ++$this->admissions;
        if ($this->throwOnAdmission) {
            throw new LogicException('admission failed');
        } return new ExecutionAdmission($this->admitted, $this->newlyAdmitted);
    }

    public function markRecoveryExpired(
        ExecutionOccurrenceKey $occurrence,
        ExecutionFence $expectedFence,
        ExecutionRecoveryFence $recoveryFence,
        int $nowUs,
        string $sourceIdentity,
    ): ExecutionRecoveryResult {
        return ExecutionRecoveryResult::result(ExecutionRecoveryResult::STALE);
    }

    public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
    {
        ++$this->observations;
        return $this->admitted;
    }

    public function hasTaskHistory(string $taskKey): bool
    {
        return true;
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
        return new ExecutionLedgerRecoveryPage([], null);
    }

    public function claim(ExecutionOccurrenceKey $o, ExecutionFence $f, int $a, ?int $d, ?ExecutionLaunchDescriptor $descriptor = null, ?string $permissionId = null): ExecutionClaimResult
    {
        throw new LogicException();
    }

    public function launchUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $d, string $p, int $at, callable $start, int $nowUs): ExecutionDispatchResult
    {
        $this->events[] = 'launch';
        try {
            $start();
        } catch (Throwable $e) {
            return ExecutionDispatchResult::ambiguous($this->admitted, $e->getMessage());
        }
        return ExecutionDispatchResult::accepted($this->admitted);
    }

    public function prepareCommandUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $rootDescriptor, ExecutionLaunchDescriptor $commandDescriptor, int $at, int $nowUs): ExecutionDispatchResult
    {
        return ExecutionDispatchResult::stale();
    }

    public function launchCommandUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $rootDescriptor, ExecutionCommandScope $preparedScope, int $at, callable $start, int $nowUs): ExecutionDispatchResult
    {
        return ExecutionDispatchResult::stale();
    }

    public function observeCommandUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $rootDescriptor, ExecutionLaunchDescriptor $commandDescriptor, ExecutionLaunchObservation $observation): ExecutionDispatchResult
    {
        return ExecutionDispatchResult::stale();
    }

    public function repairBeforeStartUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, callable $absenceProof, int $nowUs): ExecutionDispatchResult
    {
        throw new LogicException();
    }

    public function rotateAfterClosureUnderLock(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $r, ExecutionFence $n, int $nowUs, ExecutionFence $e, string $s): ExecutionGenerationResult
    {
        throw new LogicException();
    }

    public function reserveDispatch(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionDispatchReservation $r): ExecutionDispatchResult
    {
        $this->events[] = 'reserve';
        return $this->dispatch ?? ExecutionDispatchResult::stale();
    }

    public function redispatchSameGeneration(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $recoveryFence, ExecutionDispatchReservation $r, int $nowUs, string $sourceIdentity): ExecutionDispatchResult
    {
        ++$this->redispatches;
        return $this->redispatch ?? ExecutionDispatchResult::stale();
    }

    public function observeLaunch(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $recoveryFence, ExecutionLaunchObservation $v, string $sourceIdentity): ExecutionDispatchResult
    {
        ++$this->launchObservations;
        return ExecutionDispatchResult::accepted($this->admitted);
    }

    public function recover(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, int $a, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionRecoveryResult
    {
        throw new LogicException();
    }

    public function revoke(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, int $a, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionRevocationResult
    {
        throw new LogicException();
    }

    public function allocateGeneration(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, ExecutionFence $n, int $a, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionGenerationResult
    {
        ++$this->generationAllocations;
        throw new LogicException();
    }

    public function recordOutcome(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionOutcome $v, int $a): ExecutionTerminalResult
    {
        throw new LogicException();
    }
}

final class CoordinatorFaultLedger implements ExecutionLedgerCommandInterface, ExecutionLedgerQueryInterface
{
    private bool $crash = true;

    public function __construct(private readonly ExecutionLedgerCommandInterface&ExecutionLedgerQueryInterface $inner) {}

    public function repairIndexes(): void {}

    public function admit(ExecutionLedgerRecord $record): ExecutionAdmission
    {
        return $this->inner->admit($record);
    }

    public function markRecoveryExpired(
        ExecutionOccurrenceKey $occurrence,
        ExecutionFence $expectedFence,
        ExecutionRecoveryFence $recoveryFence,
        int $nowUs,
        string $sourceIdentity,
    ): ExecutionRecoveryResult {
        return $this->inner->markRecoveryExpired($occurrence, $expectedFence, $recoveryFence, $nowUs, $sourceIdentity);
    }

    public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
    {
        return $this->inner->observe($occurrence);
    }

    public function hasTaskHistory(string $taskKey): bool
    {
        return $this->inner->hasTaskHistory($taskKey);
    }

    public function activeExecutions(string $sourceIdentity): array
    {
        return $this->inner->activeExecutions($sourceIdentity);
    }

    public function ownershipHoldingExecutions(string $sourceIdentity): array
    {
        return $this->inner->ownershipHoldingExecutions($sourceIdentity);
    }

    public function recoveryPage(string $sourceIdentity, ?string $cursor, int $limit): ExecutionLedgerRecoveryPage
    {
        return $this->inner->recoveryPage($sourceIdentity, $cursor, $limit);
    }

    public function claim(ExecutionOccurrenceKey $o, ExecutionFence $f, int $a, ?int $d, ?ExecutionLaunchDescriptor $descriptor = null, ?string $permissionId = null): ExecutionClaimResult
    {
        return $this->inner->claim($o, $f, $a, $d, $descriptor, $permissionId);
    }

    public function launchUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $d, string $p, int $at, callable $start, int $nowUs): ExecutionDispatchResult
    {
        return $this->inner->launchUnderLock($o, $f, $r, $s, $d, $p, $at, $start, $nowUs);
    }

    public function prepareCommandUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $rootDescriptor, ExecutionLaunchDescriptor $commandDescriptor, int $at, int $nowUs): ExecutionDispatchResult
    {
        return $this->inner->prepareCommandUnderLock($o, $f, $r, $s, $rootDescriptor, $commandDescriptor, $at, $nowUs);
    }

    public function launchCommandUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $rootDescriptor, ExecutionCommandScope $preparedScope, int $at, callable $start, int $nowUs): ExecutionDispatchResult
    {
        return $this->inner->launchCommandUnderLock($o, $f, $r, $s, $rootDescriptor, $preparedScope, $at, $start, $nowUs);
    }

    public function observeCommandUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $rootDescriptor, ExecutionLaunchDescriptor $commandDescriptor, ExecutionLaunchObservation $observation): ExecutionDispatchResult
    {
        return $this->inner->observeCommandUnderLock($o, $f, $r, $s, $rootDescriptor, $commandDescriptor, $observation);
    }

    public function repairBeforeStartUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, callable $absenceProof, int $nowUs): ExecutionDispatchResult
    {
        return $this->inner->repairBeforeStartUnderLock($o, $f, $r, $s, $absenceProof, $nowUs);
    }

    public function rotateAfterClosureUnderLock(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $r, ExecutionFence $n, int $nowUs, ExecutionFence $e, string $s): ExecutionGenerationResult
    {
        return $this->inner->rotateAfterClosureUnderLock($o, $r, $n, $nowUs, $e, $s);
    }

    public function reserveDispatch(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionDispatchReservation $r): ExecutionDispatchResult
    {
        if ($this->crash) {
            $this->crash = false;
            throw new LogicException('simulated crash before reserveDispatch');
        }
        return $this->inner->reserveDispatch($o, $f, $r);
    }

    public function redispatchSameGeneration(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $recoveryFence, ExecutionDispatchReservation $r, int $nowUs, string $sourceIdentity): ExecutionDispatchResult
    {
        return $this->inner->redispatchSameGeneration($o, $f, $recoveryFence, $r, $nowUs, $sourceIdentity);
    }

    public function observeLaunch(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $recoveryFence, ExecutionLaunchObservation $v, string $sourceIdentity): ExecutionDispatchResult
    {
        return $this->inner->observeLaunch($o, $f, $recoveryFence, $v, $sourceIdentity);
    }

    public function recover(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, int $a, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionRecoveryResult
    {
        return $this->inner->recover($o, $f, $a, $expectedFence, $sourceIdentity);
    }

    public function revoke(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, int $a, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionRevocationResult
    {
        return $this->inner->revoke($o, $f, $a, $expectedFence, $sourceIdentity);
    }

    public function allocateGeneration(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, ExecutionFence $n, int $a, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionGenerationResult
    {
        return $this->inner->allocateGeneration($o, $f, $n, $a, $expectedFence, $sourceIdentity);
    }

    public function recordOutcome(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionOutcome $v, int $a): ExecutionTerminalResult
    {
        return $this->inner->recordOutcome($o, $f, $v, $a);
    }
}
