<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Yiisoft\Scheduler\Config\PreparedConfiguration;
use Yiisoft\Scheduler\Integration\Async\{
    ExecutionAdmission, ExecutionClaimResult, ExecutionDispatchResult, ExecutionDispatchReservation, ExecutionFence,
    ExecutionGenerationResult, ExecutionIdentity, ExecutionLaunchObservation, ExecutionLedgerInterface, ExecutionLedgerRecord,
    ExecutionOccurrenceKey, ExecutionOutcome, ExecutionRecoveryFence, ExecutionRecoveryResult, ExecutionRevocationResult,
    ExecutionTerminalResult, ExecutionWorkerCapability, ExecutionWorkerClaimRejectedException,
    ExecutionWorkerPreClaimValidatorInterface, ExecutionWorkerRuntimeComposer, ExecutionWorkerRuntimeFactoryInterface,
    ExecutionWorkerRuntimeInterface,
};

use const JSON_THROW_ON_ERROR;

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
            'format' => 3,
            'source' => ['source', 'revision'],
            'task' => [
                'id' => 'task', 'mode' => 'async', 'timeout' => [1.0, 2.0],
                'metadata' => ['nested' => ['policy' => ['leaf' => 'value']]],
                'schedule' => 'schedule', 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null],
            ],
        ];
        $capability = new ExecutionWorkerCapability(new ExecutionOccurrenceKey('schedule', 'task', 'occurrence'), 'attempt', 'run-1', 'execution-1', 3, 'fence-3', 'manifest-v1', 'task-v1', 'ledger://one', 'runtime://one', 'source-revision', $policy);

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

    public function testCapabilityUsesStrictVersionedEnvelopeAndFixedOrder(): void
    {
        self::assertSame(
            ['version', 'occurrence', 'attempt', 'run', 'execution', 'generation', 'fence', 'configurationFingerprint', 'taskVersion', 'ledger', 'runtime', 'sourceRevision', 'policy'],
            array_keys($this->capability()->toArray()),
        );
        foreach ([[], ['version' => 1], ['version' => 2]] as $change) {
            $value = $this->capability()->toArray();
            $value = $change === [] ? array_diff_key($value, ['version' => true]) : array_replace($value, $change);
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
            new ExecutionIdentity('schedule', 'task', 'occurrence', 'attempt', 'run-1', 'execution-1'),
            1,
            new ExecutionFence(3, 'fence-3'),
            'launch-required',
            'dispatch',
            'manifest-v2',
            $capability->policy,
            1,
            1,
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

    public function testPreClaimValidatorRunsBeforeClaim(): void
    {
        $factory = new ValidatingFactory();
        $ledger = new RecordingLedger(ExecutionClaimResult::accepted($this->record()));
        $runtime = (new ExecutionWorkerRuntimeComposer($ledger, $factory))->compose($this->capability(), 10, 20);
        self::assertTrue($factory->validated);
        self::assertSame(1, $ledger->claimCalls);
        self::assertNotNull($runtime);
    }

    public function testAcceptedClaimBootstrapsAfterMatchingIdentityAndFence(): void
    {
        $capability = $this->capability();
        $record = new ExecutionLedgerRecord(
            $capability->occurrence,
            new ExecutionIdentity('schedule', 'task', 'occurrence', 'attempt', 'run-1', 'execution-1'),
            1,
            new ExecutionFence(3, 'fence-3'),
            'launch-required',
            'dispatch',
            'manifest-v1',
            $capability->policy,
            1,
            1,
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
        return new ExecutionWorkerCapability(new ExecutionOccurrenceKey('schedule', 'task', 'occurrence'), 'attempt', 'run-1', 'execution-1', 3, 'fence-3', 'manifest-v1', hash('sha256', PreparedConfiguration::canonical($this->policy())), 'ledger://one', 'runtime://one', $sourceRevision ?? 'source-revision', $this->policy());
    }

    private function policy(): array
    {
        return ['format' => 3, 'source' => [null, null], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => null, 'metadata' => [], 'schedule' => 'schedule', 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]];
    }

    private function record(): ExecutionLedgerRecord
    {
        $capability = $this->capability();
        return new ExecutionLedgerRecord($capability->occurrence, new ExecutionIdentity('schedule', 'task', 'occurrence', 'attempt', 'run-1', 'execution-1'), 1, new ExecutionFence(3, 'fence-3'), 'launch-required', 'dispatch', 'manifest-v1', $this->policy());
    }
}

class RecordingFactory implements ExecutionWorkerRuntimeFactoryInterface
{
    public bool $called = false;
    public ?ExecutionWorkerRuntimeInterface $runtime = null;

    public function create(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $claimedRecord): ExecutionWorkerRuntimeInterface
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

final class RecordingLedger implements ExecutionLedgerInterface
{
    public int $claimCalls = 0;

    public function __construct(private readonly ExecutionClaimResult $claimResult) {}

    public function admit(ExecutionLedgerRecord $record): ExecutionAdmission
    {
        throw new LogicException();
    }

    public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
    {
        return $this->claimResult->record;
    }

    public function hasTaskHistory(string $scheduleKey, string $taskKey): bool
    {
        return false;
    }

    public function claim(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, int $at, ?int $claimLeaseDeadline): ExecutionClaimResult
    {
        ++$this->claimCalls;
        return $this->claimResult;
    }

    public function reserveDispatch(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionDispatchReservation $r): ExecutionDispatchResult
    {
        throw new LogicException();
    }

    public function redispatchSameGeneration(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionDispatchReservation $r, int $at): ExecutionDispatchResult
    {
        throw new LogicException();
    }

    public function observeLaunch(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionLaunchObservation $observation): ExecutionDispatchResult
    {
        throw new LogicException();
    }

    public function recover(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, int $at): ExecutionRecoveryResult
    {
        throw new LogicException();
    }

    public function revoke(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, int $at): ExecutionRevocationResult
    {
        throw new LogicException();
    }

    public function allocateGeneration(ExecutionOccurrenceKey $o, ExecutionRecoveryFence $f, ExecutionFence $newFence, int $at): ExecutionGenerationResult
    {
        throw new LogicException();
    }

    public function recordOutcome(ExecutionOccurrenceKey $o, ExecutionFence $f, ExecutionOutcome $outcome, int $at): ExecutionTerminalResult
    {
        throw new LogicException();
    }
}
