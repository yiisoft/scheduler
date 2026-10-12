<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use LogicException;
use Yiisoft\Scheduler\Config\{PreparedConfiguration, TimeoutPolicy};
use Yiisoft\Scheduler\Integration\Async\{
    ExecutionIdentity, ExecutionBinding, ExecutionAdmission, ExecutionClaimResult,
    ExecutionDispatchReservation, ExecutionDispatchResult, ExecutionFence, ExecutionGenerationResult,
    ExecutionIntegrationCoordinator, ExecutionLaunchObservation, ExecutionProcessClosure, ExecutionLedgerInterface, ExecutionLedgerRecord,
    ExecutionOccurrenceCandidate, ExecutionOccurrenceKey, ExecutionOccurrenceSourceInterface, OccurrenceSkipReason, ExecutionOutcome,
    ExecutionRecoveryFence, ExecutionRecoveryResult, ExecutionRevocationResult, ExecutionTerminalResult, ExecutionLedgerState,
    ExecutionWorkerCapability, ExecutionWorkerLaunchHandle, ExecutionWorkerLaunchRequest, ExecutionWorkerLauncherInterface, WorkerLifecyclePolicy,
};
use Yiisoft\Scheduler\Integration\Async\Store\FileExecutionLedger;
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
                ->dispatchNext($this->record(), new ExecutionWorkerCapability($this->occurrence(), 'wrong', 'run', 'execution', 1, 'fence', 'manifest', 'v1', 'ledger', 'runtime', 'source-revision', ['format' => 3, 'source' => [null, null], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => null, 'metadata' => [], 'schedule' => 'schedule', 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]]), $this->reservation());
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
            $ledger = new CoordinatorFaultLedger(new FileExecutionLedger($directory));

            try {
                $this->coordinator($ledger, $source, $launcher)->dispatchNext($record, $this->capability(), $this->reservation());
                self::fail('The simulated crash should interrupt dispatch before reservation.');
            } catch (LogicException $e) {
                self::assertSame('simulated crash before reserveDispatch', $e->getMessage());
            }

            self::assertSame(1, $source->acknowledgements);
            self::assertFalse($launcher->called);
            self::assertSame(ExecutionLedgerState::LAUNCH_REQUIRED, (new FileExecutionLedger($directory))->observe($record->occurrence)?->state);

            $reopened = new FileExecutionLedger($directory);
            $recovery = $reopened->recover($record->occurrence, new ExecutionRecoveryFence('recovery'), 10);
            self::assertSame(ExecutionRecoveryResult::SAME_GENERATION_UNCLAIMED, $recovery->status);
            self::assertNotNull($recovery->record);

            $restartLauncher = new CoordinatorTestLauncher();
            $restartSource = new CoordinatorTestSource(null);
            $restart = $this->coordinator($reopened, $restartSource, $restartLauncher)
                ->redispatchSameGeneration($recovery, $this->capability(), new ExecutionDispatchReservation('restart', 11, 20), 11);

            self::assertNotNull($restart->launchHandle);
            self::assertSame(1, $restartLauncher->launches);
            self::assertSame(0, $restartSource->acknowledgements);
            self::assertEquals($record->occurrence, $restart->dispatch?->record?->occurrence);
            self::assertEquals($record->correlation, $restart->dispatch?->record?->correlation);
            self::assertSame($record->attempt, $restart->dispatch?->record?->attempt);
            self::assertEquals($record->fence, $restart->dispatch?->record?->fence);
            self::assertEquals($record->recoveryFence, $restart->dispatch?->record?->recoveryFence);
            self::assertSame(ExecutionLedgerState::LAUNCH_REQUESTED, (new FileExecutionLedger($directory))->observe($record->occurrence)?->state);
            self::assertSame('restart', (new FileExecutionLedger($directory))->observe($record->occurrence)?->dispatchReservation?->dispatcherId);
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
            ->redispatchSameGeneration(ExecutionRecoveryResult::sameGenerationUnclaimed($this->record()), $this->capability(), $this->reservation(), 10);

        self::assertNotNull($result->launchHandle);
        self::assertSame(0, $ledger->generationAllocations);

        $launcher = new CoordinatorTestLauncher();
        $ledger = new CoordinatorTestLedger($this->record(), redispatch: ExecutionDispatchResult::accepted($this->record()));
        $result = $this->coordinator($ledger, new CoordinatorTestSource(null), $launcher)
            ->redispatchSameGeneration(ExecutionRecoveryResult::sameGenerationUnclaimed($this->record()), $this->capability(), $this->reservation(), 10);
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

    private function coordinator(ExecutionLedgerInterface $ledger, CoordinatorTestSource $source, ?CoordinatorTestLauncher $launcher = null): ExecutionIntegrationCoordinator
    {
        return new ExecutionIntegrationCoordinator($ledger, $source, $launcher ?? new CoordinatorTestLauncher());
    }

    private function occurrence(): ExecutionOccurrenceKey
    {
        return new ExecutionOccurrenceKey('schedule', 'task', 'occurrence');
    }

    private function reservation(): ExecutionDispatchReservation
    {
        return new ExecutionDispatchReservation('d', 1, 2);
    }

    private function candidate(string $cursor = 'cursor-1'): ExecutionOccurrenceCandidate
    {
        $mapping = new ExecutionBinding(
            'schedule',
            'task',
            'occurrence',
            1,
            new ExecutionIdentity('schedule', 'task', 'occurrence', 'attempt', 'run', 'execution'),
            'manifest',
            ['format' => 3, 'source' => [null, null], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [1.0, null], 'metadata' => [], 'schedule' => 'schedule', 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]],
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
        $policy = ['format' => 3, 'source' => [null, null], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [1.0, null], 'metadata' => [], 'schedule' => 'schedule', 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]];
        return new ExecutionWorkerCapability($this->occurrence(), 'attempt', 'run', 'execution', 1, 'fence', 'manifest', hash('sha256', PreparedConfiguration::canonical($policy)), 'ledger', 'runtime', 'source-revision', $policy);
    }

    private function record(string $state = 'launch-required'): ExecutionLedgerRecord
    {
        $candidate = $this->candidate();
        return new ExecutionLedgerRecord($this->occurrence(), new ExecutionIdentity('schedule', 'task', 'occurrence', 'attempt', 'run', 'execution'), 1, new ExecutionFence(1, 'fence'), $state, 'dispatch', 'manifest', ['format' => 3, 'source' => [null, null], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [1.0, null], 'metadata' => [], 'schedule' => 'schedule', 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]], 1, 1, $state === 'succeeded' ? new ExecutionOutcome('succeeded') : null, ['occurrenceCursor' => $candidate->cursor, 'taskMappingIdentity' => $candidate->taskMappingIdentity()], recoveryFence: new ExecutionRecoveryFence('recovery'));
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
    public int $launches = 0;
    public ?ExecutionWorkerLaunchRequest $request = null;
    private array $events;

    public function __construct(array &$events = [])
    {
        $this->events = & $events;
    }

    public function launch(ExecutionWorkerLaunchRequest $request): ExecutionWorkerLaunchHandle
    {
        $this->called = true;
        $this->request = $request;
        ++$this->launches;
        $this->events[] = 'launch';
        return new ExecutionWorkerLaunchHandle('handle');
    }
}

final class CoordinatorTestLedger implements ExecutionLedgerInterface
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

    public function admit(ExecutionLedgerRecord $record): ExecutionAdmission
    {
        ++$this->admissions;
        if ($this->throwOnAdmission) {
            throw new LogicException('admission failed');
        } return new ExecutionAdmission($this->admitted, $this->newlyAdmitted);
    }

    public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
    {
        ++$this->observations;
        return $this->admitted;
    }

    public function hasTaskHistory(string $scheduleKey, string $taskKey): bool
    {
        return true;
    }

    public function claim(ExecutionOccurrenceKey $o, ExecutionFence $f, int $a, ?int $d): ExecutionClaimResult
    {
        throw new LogicException();
    }

    public function reserveDispatch(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionDispatchReservation $r): ExecutionDispatchResult
    {
        $this->events[] = 'reserve';
        return $this->dispatch ?? ExecutionDispatchResult::stale();
    }

    public function redispatchSameGeneration(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionDispatchReservation $r, int $a): ExecutionDispatchResult
    {
        ++$this->redispatches;
        return $this->redispatch ?? ExecutionDispatchResult::stale();
    }

    public function observeLaunch(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionLaunchObservation $v): ExecutionDispatchResult
    {
        ++$this->launchObservations;
        return ExecutionDispatchResult::accepted($this->admitted);
    }

    public function recover(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, int $a): ExecutionRecoveryResult
    {
        throw new LogicException();
    }

    public function revoke(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, int $a): ExecutionRevocationResult
    {
        throw new LogicException();
    }

    public function allocateGeneration(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, ExecutionFence $n, int $a): ExecutionGenerationResult
    {
        ++$this->generationAllocations;
        throw new LogicException();
    }

    public function recordOutcome(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionOutcome $v, int $a): ExecutionTerminalResult
    {
        throw new LogicException();
    }
}

final class CoordinatorFaultLedger implements ExecutionLedgerInterface
{
    private bool $crash = true;

    public function __construct(private readonly ExecutionLedgerInterface $inner) {}

    public function admit(ExecutionLedgerRecord $record): ExecutionAdmission
    {
        return $this->inner->admit($record);
    }

    public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
    {
        return $this->inner->observe($occurrence);
    }

    public function hasTaskHistory(string $scheduleKey, string $taskKey): bool
    {
        return $this->inner->hasTaskHistory($scheduleKey, $taskKey);
    }

    public function claim(ExecutionOccurrenceKey $o, ExecutionFence $f, int $a, ?int $d): ExecutionClaimResult
    {
        return $this->inner->claim($o, $f, $a, $d);
    }

    public function reserveDispatch(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionDispatchReservation $r): ExecutionDispatchResult
    {
        if ($this->crash) {
            $this->crash = false;
            throw new LogicException('simulated crash before reserveDispatch');
        }
        return $this->inner->reserveDispatch($o, $f, $r);
    }

    public function redispatchSameGeneration(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionDispatchReservation $r, int $a): ExecutionDispatchResult
    {
        return $this->inner->redispatchSameGeneration($o, $f, $r, $a);
    }

    public function observeLaunch(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionLaunchObservation $v): ExecutionDispatchResult
    {
        return $this->inner->observeLaunch($o, $f, $v);
    }

    public function recover(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, int $a): ExecutionRecoveryResult
    {
        return $this->inner->recover($o, $f, $a);
    }

    public function revoke(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, int $a): ExecutionRevocationResult
    {
        return $this->inner->revoke($o, $f, $a);
    }

    public function allocateGeneration(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, ExecutionFence $n, int $a): ExecutionGenerationResult
    {
        return $this->inner->allocateGeneration($o, $f, $n, $a);
    }

    public function recordOutcome(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionOutcome $v, int $a): ExecutionTerminalResult
    {
        return $this->inner->recordOutcome($o, $f, $v, $a);
    }
}
