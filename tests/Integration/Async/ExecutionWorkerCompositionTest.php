<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async;

use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchDescriptor;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Yiisoft\Scheduler\Config\PreparedConfiguration;
use Yiisoft\Scheduler\Integration\Async\{
    ExecutionAdmission, ExecutionClaimResult, ExecutionCommandScope, ExecutionDispatchResult, ExecutionDispatchReservation, ExecutionFence,
    ExecutionGenerationResult, ExecutionIdentity, ExecutionLaunchObservation, ExecutionLedgerCommandInterface, ExecutionLedgerQueryInterface, ExecutionLedgerRecord, ExecutionLedgerRecoveryPage,
    ExecutionProcessIdentity, ExecutionStartObservation,
    ExecutionOccurrenceKey, ExecutionOutcome, ExecutionRecoveryFence, ExecutionRecoveryResult, ExecutionRevocationResult,
    ExecutionTerminalResult, ExecutionWorkerCapability, ExecutionWorkerClaimRejectedException,
    ExecutionWorkerPreClaimValidatorInterface, ExecutionWorkerRuntimeComposer, ExecutionWorkerRuntimeFactoryInterface,
    ExecutionWorkerRuntimeInterface,
};

use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;

final class ExecutionWorkerCompositionTest extends TestCase
{
    public function testCapabilityHasNoRemainingBudgetCapabilityOrTransportField(): void
    {
        $type = new ReflectionClass(ExecutionWorkerCapability::class);
        $constructor = $type->getConstructor();
        self::assertNotNull($constructor);
        self::assertFalse($type->hasProperty('remainingBudget'));
        self::assertFalse($type->hasMethod('remainingBudget'));
        self::assertSame(
            [],
            array_filter(
                $constructor->getParameters(),
                static fn($parameter): bool => $parameter->getName() === 'remainingBudget',
            ),
        );

        $value = $this->capability()->toArray();
        $value['remainingBudget'] = 1.0;
        $this->assertDecodeRejected($value);
    }

    public function testCapabilityRoundTripPreservesFractionalPolicyAndNestedMetadata(): void
    {
        $policy = [
            'schema' => 'dev',
            'source' => ['source', 'source-revision'],
            'task' => [
                'id' => 'task', 'mode' => 'async', 'timeout' => [1.0, 2.0], 'recoveryMaxAge' => 180.0,
                'metadata' => ['nested' => ['policy' => ['leaf' => 'value']]],
                'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null],
            ],
        ];
        $capability = new ExecutionWorkerCapability(new ExecutionOccurrenceKey('task', 'occurrence'), 'attempt', 'run-1', 'execution-1', 4, 'fence-4', 'manifest-v1', 'task-v1', 'ledger://one', 'runtime://one', 'source-revision', $policy, new ExecutionRecoveryFence('recovery-4', 4));

        self::assertStringContainsString('1.0', $capability->encode());
        self::assertSame($capability->toArray(), ExecutionWorkerCapability::decode($capability->encode())->toArray());
    }

    public function testCapabilityRoundTripAndRedactedDiagnostic(): void
    {
        $capability = $this->capability('source-revision');
        self::assertSame($capability->toArray(), ExecutionWorkerCapability::decode($capability->encode())->toArray());
        self::assertSame('[redacted]', $capability->diagnostic()['fence']);
        self::assertArrayNotHasKey('argv', $capability->toArray());
        self::assertArrayNotHasKey('bootstrap', $capability->toArray());
        self::assertSame('source-revision', ExecutionWorkerCapability::decode($capability->encode())->sourceRevision);
    }

    public function testCapabilityRejectsDuplicateMembersAtAnyObjectNestingLevel(): void
    {
        $json = $this->capability()->encode();
        $json = substr_replace($json, '"schema":"dev",', 1, 0);
        $this->expectException(InvalidArgumentException::class);
        ExecutionWorkerCapability::decode($json);
    }

    public function testCapabilityRejectsMalformedSourceRevision(): void
    {
        $capability = $this->capability()->toArray();
        $capability['sourceRevision'] = ['not', 'scalar'];
        $this->expectException(InvalidArgumentException::class);
        ExecutionWorkerCapability::decode(json_encode($capability, JSON_THROW_ON_ERROR));
    }

    public function testCapabilityRejectsTreesNulsAndOversizedValues(): void
    {
        $capability = $this->capability()->toArray();
        $capability['taskVersion'] = ['not', 'scalar'];
        $this->expectException(InvalidArgumentException::class);
        ExecutionWorkerCapability::decode(json_encode($capability, JSON_THROW_ON_ERROR));
    }

    public function testCapabilityUsesStrictCurrentSchemaEnvelopeAndFixedOrder(): void
    {
        self::assertSame(
            ['schema', 'occurrence', 'attempt', 'run', 'execution', 'generation', 'fence', 'configurationFingerprint', 'taskVersion', 'ledger', 'runtime', 'sourceRevision', 'policy', 'recoveryFence', 'launchDescriptor', 'permissionId'],
            array_keys($this->capability()->toArray()),
        );
        foreach ([[], ['schema' => 'legacy'], ['schema' => 'dev-old']] as $change) {
            $value = $this->capability()->toArray();
            $value = $change === [] ? array_diff_key($value, ['schema' => true]) : array_replace($value, $change);
            $this->assertDecodeRejected($value);
        }
        $oldKey = $this->capability()->toArray();
        $oldKey['manifest'] = $oldKey['configurationFingerprint'];
        unset($oldKey['configurationFingerprint']);
        $this->assertDecodeRejected($oldKey);
    }

    public function testCapabilityRejectsMissingInvalidAndExtraTransportFields(): void
    {
        $value = $this->capability()->toArray();
        foreach ([
            ['sourceRevision' => null],
            ['sourceRevision' => ''],
            ['sourceRevision' => str_repeat('x', 513)],
            ['sourceRevision' => "bad\0revision"],
            ['extra' => 'not-admitted'],
        ] as $change) {
            $this->assertDecodeRejected(array_replace($value, $change));
        }
        $this->assertDecodeRejected($value, str_repeat('x', 8193));
    }

    public function testRejectedClaimDoesNotBootstrap(): void
    {
        $factory = new RecordingFactory();
        $ledger = new RecordingLedger(ExecutionClaimResult::rejected('revoked'));
        $this->expectException(ExecutionWorkerClaimRejectedException::class);
        try {
            (new ExecutionWorkerRuntimeComposer($ledger, $factory))->compose($this->capability(), 10, 20);
        } finally {
            self::assertFalse($factory->called);
        }
    }

    public function testStaleClaimDoesNotBootstrap(): void
    {
        $factory = new RecordingFactory();
        $ledger = new RecordingLedger(ExecutionClaimResult::rejected('stale fence'));
        $this->expectException(ExecutionWorkerClaimRejectedException::class);
        try {
            (new ExecutionWorkerRuntimeComposer($ledger, $factory))->compose($this->capability(), 10, 20);
        } finally {
            self::assertFalse($factory->called);
        }
    }

    public function testMismatchedConfigurationFingerprintAndTaskReferenceAreRejectedBeforeBootstrap(): void
    {
        $capability = $this->capability();
        $record = new ExecutionLedgerRecord(
            $capability->occurrence,
            new ExecutionIdentity('task', 'occurrence', 'attempt', 'run-1', 'execution-1'),
            1,
            new ExecutionFence(4, 'fence-4'),
            'launch-required',
            'manifest-v2',
            $capability->policy,
            1,
            1,
            recoveryFence: new ExecutionRecoveryFence('recovery-4', 4),
            sourceIdentity: 'source',
            sourceRevision: 'source-revision',
            processIdentity: new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id'),
            startAnchorIdentity: new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id'),
            startObservation: new ExecutionStartObservation(1700000000, 123456789),
            recoveryAge: 180.0,
            recoveryDeadline: 1880000000,
        );
        $factory = new RecordingFactory();
        $this->expectException(ExecutionWorkerClaimRejectedException::class);
        try {
            (new ExecutionWorkerRuntimeComposer(new RecordingLedger(ExecutionClaimResult::accepted($record)), $factory))
                ->compose($capability, 10, 20);
        } finally {
            self::assertFalse($factory->called);
        }
    }

    public function testUnstartedRecordWithoutRecoveryAgeMatchesCapability(): void
    {
        $capability = $this->capability();
        $record = $this->record(false);
        $factory = new RecordingFactory();

        $this->expectException(ExecutionWorkerClaimRejectedException::class);
        try {
            (new ExecutionWorkerRuntimeComposer(new RecordingLedger(ExecutionClaimResult::accepted($record)), $factory))
                ->compose($capability, 10, 20);
        } finally {
            self::assertFalse($factory->called);
        }
    }

    public function testPreClaimValidatorRunsBeforeClaim(): void
    {
        $factory = new ValidatingFactory();
        $ledger = new RecordingLedger(ExecutionClaimResult::accepted($this->record()));
        $runtime = (new ExecutionWorkerRuntimeComposer($ledger, $factory))->compose($this->capability(), 10, 20);
        self::assertTrue($factory->validated);
        self::assertSame(1, $ledger->claimCalls);
        self::assertNotNull($runtime);
    }

    public function testStartObservationDoesNotPreventFreshWorkerClaim(): void
    {
        $capability = $this->capability();
        $record = new ExecutionLedgerRecord(
            $capability->occurrence,
            new ExecutionIdentity('task', 'occurrence', 'attempt', 'run-1', 'execution-1'),
            1,
            new ExecutionFence(4, 'fence-4'),
            'needs-resolution',
            'manifest-v1',
            $capability->policy,
            1,
            1,
            dispatchReservation: new ExecutionDispatchReservation('fixture', 1, 2, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ExecutionDispatchReservation::OBSERVED),
            launchObservation: new ExecutionLaunchObservation(123, null, 'started', 1700000000, processIdentity: new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id'), startObservation: new ExecutionStartObservation(1700000000, 123456789)),
            attemptStartObservation: new ExecutionStartObservation(1700000000, 123456789),
            launchDescriptor: $capability->launchDescriptor,
            recoveryFence: new ExecutionRecoveryFence('recovery-4', 4),
            sourceIdentity: 'source',
            sourceRevision: 'source-revision',
            processIdentity: new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id'),
            startAnchorIdentity: new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id'),
            startObservation: new ExecutionStartObservation(1700000000, 123456789),
            recoveryAge: 180.0,
            recoveryDeadline: 1880000000,
        );
        $factory = new RecordingFactory();
        $runtime = (new ExecutionWorkerRuntimeComposer(new RecordingLedger(ExecutionClaimResult::accepted($record)), $factory))
            ->compose($capability, 10, 20);

        self::assertTrue($factory->called);
        self::assertSame($runtime, $factory->runtime);
    }

    public function testAcceptedClaimBootstrapsAfterMatchingIdentityAndFence(): void
    {
        $capability = $this->capability();
        $record = new ExecutionLedgerRecord(
            $capability->occurrence,
            new ExecutionIdentity('task', 'occurrence', 'attempt', 'run-1', 'execution-1'),
            1,
            new ExecutionFence(4, 'fence-4'),
            'needs-resolution',
            'manifest-v1',
            $capability->policy,
            1,
            1,
            dispatchReservation: new ExecutionDispatchReservation('fixture', 1, 2, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ExecutionDispatchReservation::OBSERVED),
            launchObservation: new ExecutionLaunchObservation(123, null, 'started', 1700000000, processIdentity: new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id'), startObservation: new ExecutionStartObservation(1700000000, 123456789)),
            attemptStartObservation: new ExecutionStartObservation(1700000000, 123456789),
            launchDescriptor: $capability->launchDescriptor,
            recoveryFence: new ExecutionRecoveryFence('recovery-4', 4),
            sourceIdentity: 'source',
            sourceRevision: 'source-revision',
            processIdentity: new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id'),
            startAnchorIdentity: new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id'),
            startObservation: new ExecutionStartObservation(1700000000, 123456789),
            recoveryAge: 180.0,
            recoveryDeadline: 1880000000,
        );
        $factory = new RecordingFactory();
        $runtime = (new ExecutionWorkerRuntimeComposer(new RecordingLedger(ExecutionClaimResult::accepted($record)), $factory))
            ->compose($capability, 10, 20);
        self::assertTrue($factory->called);
        self::assertEquals($runtime, $factory->runtime);
    }

    /** @param array<string, mixed> $value */
    private function assertDecodeRejected(array $value, ?string $transport = null): void
    {
        try {
            ExecutionWorkerCapability::decode($transport ?? json_encode($value, JSON_THROW_ON_ERROR));
            self::fail('Invalid capability transport was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    private function capability(?string $sourceRevision = null): ExecutionWorkerCapability
    {
        return new ExecutionWorkerCapability(new ExecutionOccurrenceKey('task', 'occurrence'), 'attempt', 'run-1', 'execution-1', 4, 'fence-4', 'manifest-v1', hash('sha256', PreparedConfiguration::canonical($this->policy())), 'ledger://one', 'runtime://one', $sourceRevision ?? 'source-revision', $this->policy(), new ExecutionRecoveryFence('recovery-4', 4), new ExecutionLaunchDescriptor('execution-1', 4, 'worker-nonce', occurrence: new ExecutionOccurrenceKey('task', 'occurrence')), 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    }

    private function policy(): array
    {
        return ['schema' => 'dev', 'source' => ['source', 'source-revision'], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => null, 'recoveryMaxAge' => 180.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]];
    }

    private function record(bool $started = true): ExecutionLedgerRecord
    {
        $capability = $this->capability();
        $identity = $started ? new ExecutionProcessIdentity(123, 123, 123, 'start-ticks', 'boot-id') : null;
        return new ExecutionLedgerRecord($capability->occurrence, new ExecutionIdentity('task', 'occurrence', 'attempt', 'run-1', 'execution-1'), 1, new ExecutionFence(4, 'fence-4'), 'needs-resolution', 'manifest-v1', $this->policy(), dispatchReservation: $started ? new ExecutionDispatchReservation('fixture', 1, 2, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ExecutionDispatchReservation::OBSERVED) : null, launchObservation: $started ? new ExecutionLaunchObservation(123, null, 'started', 1700000000, processIdentity: $identity, startObservation: new ExecutionStartObservation(1700000000, 123456789)) : null, attemptStartObservation: $started ? new ExecutionStartObservation(1700000000, 123456789) : null, launchDescriptor: $started ? $capability->launchDescriptor : null, recoveryFence: new ExecutionRecoveryFence('recovery-4', 4), sourceIdentity: 'source', sourceRevision: 'source-revision', processIdentity: $identity, startAnchorIdentity: $identity, startObservation: $started ? new ExecutionStartObservation(1700000000, 123456789) : null, recoveryAge: $started ? 180.0 : null, recoveryDeadline: $started ? 1880000000 : null);
    }
}

class RecordingFactory implements ExecutionWorkerRuntimeFactoryInterface
{
    public bool $called = false;
    public ?ExecutionWorkerRuntimeInterface $runtime = null;

    public function create(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $claimedRecord, ExecutionLedgerCommandInterface $ledger): ExecutionWorkerRuntimeInterface
    {
        $this->called = true;
        return $this->runtime = new class implements ExecutionWorkerRuntimeInterface {
            public function execute(): ExecutionOutcome
            {
                return new ExecutionOutcome(ExecutionOutcome::SUCCEEDED);
            }
        };
    }
}

final class ValidatingFactory extends RecordingFactory implements ExecutionWorkerPreClaimValidatorInterface
{
    public bool $validated = false;

    public function validate(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $observedRecord): void
    {
        $this->validated = true;
    }
}

final class RecordingLedger implements ExecutionLedgerCommandInterface, ExecutionLedgerQueryInterface
{
    public int $claimCalls = 0;
    public ?ExecutionLaunchDescriptor $claimDescriptor = null;
    public ?string $claimPermissionId = null;

    public function __construct(private readonly ExecutionClaimResult $claimResult) {}

    public function markRecoveryExpired(
        ExecutionOccurrenceKey $occurrence,
        ExecutionFence $expectedFence,
        ExecutionRecoveryFence $recoveryFence,
        int $nowUs,
        string $sourceIdentity,
    ): ExecutionRecoveryResult {
        return ExecutionRecoveryResult::result(ExecutionRecoveryResult::STALE);
    }

    public function admit(ExecutionLedgerRecord $record): ExecutionAdmission
    {
        throw new LogicException();
    }

    public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
    {
        return $this->claimResult->record;
    }

    public function hasTaskHistory(string $taskKey): bool
    {
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
        return new ExecutionLedgerRecoveryPage([], null);
    }

    public function claim(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, int $at, ?int $claimLeaseDeadline, ?ExecutionLaunchDescriptor $descriptor = null, ?string $permissionId = null): ExecutionClaimResult
    {
        ++$this->claimCalls;
        $this->claimDescriptor = $descriptor;
        $this->claimPermissionId = $permissionId;
        $record = $this->claimResult->record;
        if ($record === null || $record->state !== 'needs-resolution' || $record->dispatchReservation?->launchPhase !== ExecutionDispatchReservation::OBSERVED || $record->launchDescriptor === null || $descriptor === null || !$record->launchDescriptor->equals($descriptor) || $record->dispatchReservation->permissionId !== $permissionId || $record->launchObservation === null || $record->launchObservation->pid === null || $record->launchObservation->processIdentity === null || $record->launchObservation->startObservation === null) {
            return ExecutionClaimResult::rejected('fixture requires complete observed launch receipt');
        }
        return $this->claimResult;
    }

    public function launchUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $d, string $p, int $at, callable $start, int $nowUs): ExecutionDispatchResult
    {
        throw new LogicException();
    }

    public function prepareCommandUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $rootDescriptor, ExecutionLaunchDescriptor $commandDescriptor, int $at, int $nowUs): ExecutionDispatchResult
    {
        $record = $this->claimResult->record;
        if ($record === null) {
            return ExecutionDispatchResult::stale();
        }
        return ExecutionDispatchResult::accepted($record->transition(commandScope: new ExecutionCommandScope(
            $commandDescriptor,
            new ExecutionDispatchReservation('fixture-command', $at, PHP_INT_MAX, str_repeat('a', 32)),
        )));
    }

    public function launchCommandUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $d, ExecutionCommandScope $scope, int $at, callable $start, int $nowUs): ExecutionDispatchResult
    {
        $start();
        return ExecutionDispatchResult::accepted($this->claimResult->record);
    }

    public function observeCommandUnderLock(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $r, string $s, ExecutionLaunchDescriptor $rootDescriptor, ExecutionLaunchDescriptor $commandDescriptor, ExecutionLaunchObservation $observation): ExecutionDispatchResult
    {
        return ExecutionDispatchResult::accepted($this->claimResult->record);
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
        throw new LogicException();
    }

    public function redispatchSameGeneration(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $recoveryFence, ExecutionDispatchReservation $r, int $nowUs, string $sourceIdentity): ExecutionDispatchResult
    {
        throw new LogicException();
    }

    public function observeLaunch(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionRecoveryFence $recoveryFence, ExecutionLaunchObservation $observation, string $sourceIdentity): ExecutionDispatchResult
    {
        throw new LogicException();
    }

    public function recover(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionRecoveryResult
    {
        throw new LogicException();
    }

    public function revoke(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionRevocationResult
    {
        throw new LogicException();
    }

    public function allocateGeneration(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, ExecutionFence $newFence, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionGenerationResult
    {
        throw new LogicException();
    }

    public function repairIndexes(): void {}

    public function recordOutcome(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionOutcome $outcome, int $at): ExecutionTerminalResult
    {
        throw new LogicException();
    }
}
