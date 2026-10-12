<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Integration\Async;

use Closure;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use stdClass;
use Throwable;
use Yiisoft\Schedule\Config\PhpConfigurationSource;
use Yiisoft\Schedule\Config\PreparedConfiguration;
use Yiisoft\Schedule\Executor\{CallableCommand, CommandExecutorMap, ExecutionContext, ExecutorInterface};
use Yiisoft\Schedule\Integration\Async\ExecutionAdmission;
use Yiisoft\Schedule\Integration\Async\ExecutionClaimResult;
use Yiisoft\Schedule\Integration\Async\ExecutionDispatchReservation;
use Yiisoft\Schedule\Integration\Async\ExecutionDispatchResult;
use Yiisoft\Schedule\Integration\Async\ExecutionFence;
use Yiisoft\Schedule\Integration\Async\ExecutionGenerationResult;
use Yiisoft\Schedule\Integration\Async\ExecutionIdentity;
use Yiisoft\Schedule\Integration\Async\ExecutionLaunchObservation;
use Yiisoft\Schedule\Integration\Async\ExecutionLedgerInterface;
use Yiisoft\Schedule\Integration\Async\ExecutionLedgerLocatorInterface;
use Yiisoft\Schedule\Integration\Async\ExecutionLedgerRecord;
use Yiisoft\Schedule\Integration\Async\ExecutionOccurrenceKey;
use Yiisoft\Schedule\Integration\Async\ExecutionOutcome;
use Yiisoft\Schedule\Integration\Async\ExecutionRecoveryFence;
use Yiisoft\Schedule\Integration\Async\ExecutionRecoveryResult;
use Yiisoft\Schedule\Integration\Async\ExecutionRevocationResult;
use Yiisoft\Schedule\Integration\Async\ExecutionTerminalResult;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerCapability;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerEntrypoint;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerInvocationResult;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerRuntimeFactoryInterface;
use Yiisoft\Schedule\Integration\Async\PreparedConfigurationExecutionWorkerRuntimeFactory;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerRuntimeInterface;
use Yiisoft\Schedule\Tests\Support\TestCommandExecutorRuntimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;

use function strlen;

use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;

final class ExecutionWorkerEntrypointTest extends TestCase
{
    public function testTransportIsStrictAndRejectedBeforeAnyDependency(): void
    {
        foreach ([123, '{bad}', str_repeat('x', 8193), "\0"] as $transport) {
            $ledger = new EntrypointLedger(ExecutionClaimResult::rejected('unused'));
            $resolved = false;
            $factory = new EntrypointFactory(new ExecutionOutcome(ExecutionOutcome::SUCCEEDED));
            $result = $this->entrypoint($ledger, $factory, $resolved)->invoke($transport, 20);
            self::assertSame(ExecutionWorkerInvocationResult::INVALID_CAPABILITY, $result->status);
            self::assertFalse($resolved);
            self::assertSame([], $ledger->events);
            self::assertFalse($factory->called);
        }
    }

    public function testInvalidCapabilityDoesNotResolveOrBootstrap(): void
    {
        $data = $this->capability()->toArray();
        $data['fence'] = ['not-scalar'];
        $resolved = false;
        $factory = new EntrypointFactory(new ExecutionOutcome(ExecutionOutcome::SUCCEEDED));
        $ledger = new EntrypointLedger(ExecutionClaimResult::rejected('unused'));
        $result = $this->entrypoint($ledger, $factory, $resolved)->invoke(json_encode($data, JSON_THROW_ON_ERROR), 20);
        self::assertSame(ExecutionWorkerInvocationResult::INVALID_CAPABILITY, $result->status);
        self::assertFalse($resolved);
        self::assertFalse($factory->called);
    }

    public function testLocatorIsAllowListedAndFailureHasNoTerminalWrite(): void
    {
        $capability = $this->capability();
        $locator = new class implements ExecutionLedgerLocatorInterface {
            public string $value = '';

            public function resolve(string $locator): ExecutionLedgerInterface
            {
                $this->value = $locator;
                throw new RuntimeException('unavailable');
            }
        };
        $factory = new EntrypointFactory(new ExecutionOutcome(ExecutionOutcome::SUCCEEDED));
        $ledger = new EntrypointLedger(ExecutionClaimResult::rejected('unused'));
        $result = (new ExecutionWorkerEntrypoint($locator, $factory, new EntrypointClock([10])))->invoke($capability->encode(), 20);
        self::assertSame(ExecutionWorkerInvocationResult::LOCATOR_UNAVAILABLE, $result->status);
        self::assertSame('ledger://allow-listed', $locator->value);
        self::assertSame([], $ledger->events);
        self::assertFalse($factory->called);
    }

    public function testUnlimitedWorkerClaimPassesNullableDeadlineAndCanCommitAfterArbitraryTime(): void
    {
        $capability = $this->capability();
        $ledger = new EntrypointLedger(ExecutionClaimResult::accepted($this->record($capability)));
        $factory = new EntrypointFactory(new ExecutionOutcome(ExecutionOutcome::SUCCEEDED));

        $result = $this->entrypoint($ledger, $factory, times: [PHP_INT_MAX, PHP_INT_MAX])->invoke($capability->encode(), null);

        self::assertSame(ExecutionWorkerInvocationResult::COMMITTED_SUCCEEDED, $result->status);
        self::assertSame([PHP_INT_MAX, null], $ledger->claimArgs);
        self::assertSame([PHP_INT_MAX, 'succeeded'], $ledger->terminalArgs);
    }

    public function testClaimIsOrderedBeforeFactoryAndRuntimeWithExactTimesAndFence(): void
    {
        $capability = $this->capability();
        $ledger = new EntrypointLedger(ExecutionClaimResult::accepted($this->record($capability)));
        $factory = new EntrypointFactory(new ExecutionOutcome(ExecutionOutcome::SUCCEEDED));
        $resolved = false;
        $result = $this->entrypoint($ledger, $factory, $resolved, [100, 90])->invoke($capability->encode(), 777);
        self::assertSame(ExecutionWorkerInvocationResult::COMMITTED_SUCCEEDED, $result->status);
        self::assertSame(['claim', 'terminal'], $ledger->events);
        self::assertTrue($factory->called);
        self::assertSame([100, 777], $ledger->claimArgs);
        self::assertSame([100, 'succeeded'], $ledger->terminalArgs);
        self::assertSame('fence-3', $ledger->terminalFence?->token);
        self::assertEquals($capability->occurrence, $ledger->terminalOccurrence);
    }

    public function testStaleTaskVersionIsRejectedBeforeBootstrapAndTerminalWrite(): void
    {
        $capability = $this->capability();
        $record = $this->record($capability, 'manifest-v1', 'task-old');
        $ledger = new EntrypointLedger(ExecutionClaimResult::accepted($record));
        $factory = new EntrypointFactory(new ExecutionOutcome(ExecutionOutcome::SUCCEEDED));

        $result = $this->entrypoint($ledger, $factory)->invoke($capability->encode(), 20);

        self::assertSame(ExecutionWorkerInvocationResult::CLAIM_REJECTED, $result->status);
        self::assertSame([], $ledger->events);
        self::assertSame(0, $ledger->terminalCalls);
        self::assertFalse($factory->called);
        self::assertSame('task-old', $ledger->claimResult->record?->taskSnapshot['task']['metadata']['version']);
    }

    public function testPostClaimCapabilityMismatchIsTerminalizedWithoutBootstrap(): void
    {
        $capability = $this->capability();
        $ledger = new EntrypointLedger(ExecutionClaimResult::accepted($this->record($capability)));
        $ledger->claimResult = ExecutionClaimResult::accepted($this->record($capability, 'manifest-v1', 'task-new'));
        $factory = new EntrypointFactory(new ExecutionOutcome(ExecutionOutcome::SUCCEEDED));

        $result = $this->entrypoint($ledger, $factory)->invoke($capability->encode(), 20);

        self::assertSame(ExecutionWorkerInvocationResult::POST_CLAIM_FAILED, $result->status);
        self::assertSame('worker.capability_mismatch_after_claim', $result->reason);
        self::assertSame(ExecutionOutcome::FAILED, $result->outcome?->status);
        self::assertSame(['claim', 'terminal'], $ledger->events);
        self::assertSame(1, $ledger->terminalCalls);
        self::assertSame('failed', $ledger->terminalArgs[1]);
        self::assertFalse($factory->called);
    }

    public function testPostClaimCapabilityMismatchPreservesStaleFenceTerminalOutcome(): void
    {
        $capability = $this->capability();
        $ledger = new EntrypointLedger(
            ExecutionClaimResult::accepted($this->record($capability)),
            false,
            ExecutionTerminalResult::staleFenced(),
        );
        $ledger->claimResult = ExecutionClaimResult::accepted($this->record($capability, 'manifest-v1', 'task-new'));

        $result = $this->entrypoint($ledger, new EntrypointFactory(new ExecutionOutcome(ExecutionOutcome::SUCCEEDED)))->invoke($capability->encode(), 20);

        self::assertSame(ExecutionWorkerInvocationResult::TERMINAL_STALE_FENCED, $result->status);
        self::assertSame(['claim', 'terminal'], $ledger->events);
        self::assertSame(1, $ledger->terminalCalls);
    }

    public function testClaimOperationFailureAndTypedRejectionNeverBootstrapOrTerminalize(): void
    {
        foreach ([ExecutionClaimResult::rejected('revoked'), null] as $claim) {
            $ledger = new EntrypointLedger(ExecutionClaimResult::accepted($this->record($this->capability())), $claim === null);
            $ledger->claimResult = $claim ?? ExecutionClaimResult::rejected('placeholder');
            $factory = new EntrypointFactory(new ExecutionOutcome(ExecutionOutcome::SUCCEEDED));
            $result = $this->entrypoint($ledger, $factory)->invoke($this->capability()->encode(), 20);
            self::assertSame(ExecutionWorkerInvocationResult::CLAIM_REJECTED, $result->status);
            self::assertSame(['claim'], $ledger->events);
            self::assertFalse($factory->called);
        }
    }

    public function testUnresolvedOwnershipIsReturnedWithoutLedgerOutcomeWrite(): void
    {
        $capability = $this->capability();
        $ledger = new EntrypointLedger(ExecutionClaimResult::accepted($this->record($capability)));
        $outcome = new ExecutionOutcome(ExecutionOutcome::UNRESOLVED, 'worker.ownership_unresolved', ['bounded' => true]);

        $result = $this->entrypoint($ledger, new EntrypointFactory($outcome))->invoke($capability->encode(), 20);

        self::assertSame(ExecutionWorkerInvocationResult::UNRESOLVED_OWNERSHIP, $result->status);
        self::assertSame($outcome, $result->outcome);
        self::assertSame('worker.ownership_unresolved', $result->reason);
        self::assertSame(['claim'], $ledger->events);
        self::assertSame(0, $ledger->terminalCalls);
    }

    public function testUnresolvedOwnershipReasonIsBoundedAndSecretFree(): void
    {
        $capability = $this->capability();
        $ledger = new EntrypointLedger(ExecutionClaimResult::accepted($this->record($capability)));
        $reason = 'worker.ownership_unresolved';
        $outcome = new ExecutionOutcome(ExecutionOutcome::UNRESOLVED, $reason, ['detail' => 'bounded']);
        $result = $this->entrypoint($ledger, new EntrypointFactory($outcome))->invoke($capability->encode(), 20);

        self::assertLessThanOrEqual(4096, strlen((string) $result->reason));
        self::assertStringNotContainsString('secret-', (string) $result->reason);
        self::assertSame(0, $ledger->terminalCalls);
    }

    #[DataProvider('outcomeProvider')]
    public function testAcceptedExecutionPreservesOutcome(string $outcomeStatus, string $expected): void
    {
        $capability = $this->capability();
        $outcome = new ExecutionOutcome($outcomeStatus, 'application-reason');
        $ledger = new EntrypointLedger(ExecutionClaimResult::accepted($this->record($capability)));
        $resolved = false;
        $result = $this->entrypoint($ledger, new EntrypointFactory($outcome), $resolved, [5, 6])->invoke($capability->encode(), 20);
        self::assertSame($expected, $result->status);
        self::assertSame($outcome, $result->outcome);
        self::assertSame('application-reason', $result->reason);
    }

    public static function outcomeProvider(): array
    {
        return [[ExecutionOutcome::SUCCEEDED, ExecutionWorkerInvocationResult::COMMITTED_SUCCEEDED], [ExecutionOutcome::FAILED, ExecutionWorkerInvocationResult::COMMITTED_FAILED], [ExecutionOutcome::CANCELLED, ExecutionWorkerInvocationResult::COMMITTED_CANCELLED]];
    }

    public function testMalformedConfigurationAfterClaimFailsWithoutInvokingExecutor(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'schedule-worker-');
        $marker = tempnam(sys_get_temp_dir(), 'schedule-worker-malformed-');
        unlink($marker);
        file_put_contents($path, "<?php return static function (): \\Yiisoft\\Schedule\\Config\\SchedulerConfig { if (is_file(" . var_export($marker, true) . ")) { throw new \\InvalidArgumentException('Malformed profile.'); } \$config = new \\Yiisoft\\Schedule\\Config\\SchedulerConfig(); \$config->periodic(new \\Yiisoft\\Schedule\\Executor\\CallableCommand(static fn(mixed \$probe = null): \\Yiisoft\\Schedule\\Integration\\Async\\ExecutionOutcome => new \\Yiisoft\\Schedule\\Integration\\Async\\ExecutionOutcome('succeeded'), ['probe' => 'probe']))->every(60)->id('task'); return \$config; }; ");
        $source = new PhpConfigurationSource($path);
        $configuration = $source->prepare(new TestCommandExecutorRuntimeFactory());
        $definition = $configuration->tasks['task'];
        $occurrence = new ExecutionOccurrenceKey('schedule', 'task', 'occurrence');
        $capability = new ExecutionWorkerCapability(
            $occurrence,
            'attempt',
            'run-1',
            'execution-1',
            1,
            'fence-1',
            $configuration->fingerprint(),
            $configuration->taskFingerprint($definition),
            'ledger://allow-listed',
            'runtime://one',
            $source->revision(),
            $configuration->taskPolicyProjection($definition),
        );
        $record = new ExecutionLedgerRecord(
            $occurrence,
            new ExecutionIdentity('schedule', 'task', 'occurrence', 'attempt', 'run-1', 'execution-1'),
            1,
            new ExecutionFence(1, 'fence-1'),
            'launch-required',
            'dispatch',
            $capability->configurationFingerprint,
            $configuration->taskPolicyProjection($definition),
            metadata: ['sourceId' => $source->sourceId(), 'sourceRevision' => $source->revision()],
        );
        $ledger = new EntrypointLedger(ExecutionClaimResult::accepted($record));
        $invoked = false;
        $executors = new CommandExecutorMap([
            CallableCommand::class => new class (static function () use (&$invoked): void {
                $invoked = true;
            }) implements ExecutorInterface {

                public function __construct(private readonly Closure $capture) {}

                public function execute(ExecutionContext $context): ExecutionOutcome
                {
                    ($this->capture)();
                    return new ExecutionOutcome(ExecutionOutcome::SUCCEEDED);
                }
            },
        ]);
        $factory = new PreparedConfigurationExecutionWorkerRuntimeFactory($source, new TestCommandExecutorRuntimeFactory($executors));
        file_put_contents($marker, 'malformed');

        $result = $this->entrypoint($ledger, $factory, times: [10, 20])->invoke($capability->encode(), 20);

        self::assertSame(ExecutionWorkerInvocationResult::POST_CLAIM_FAILED, $result->status);
        self::assertSame(ExecutionOutcome::FAILED, $result->outcome?->status);
        self::assertSame('worker.runtime_factory_failed', $result->reason);
        self::assertSame(1, $ledger->terminalCalls);
        self::assertSame(['claim', 'terminal'], $ledger->events);
        self::assertSame('failed', $ledger->terminalArgs[1]);
        self::assertSame(ExecutionOutcome::FAILED, $ledger->terminalOutcome?->status);
        self::assertSame('worker.runtime_factory_failed', $ledger->terminalOutcome?->reason);
        self::assertSame('fence-1', $ledger->terminalFence?->token);
        self::assertFalse($invoked);
        self::assertStringNotContainsString('secret-config-detail', $result->reason);
        self::assertStringNotContainsString('secret-config-detail', (string) $result->outcome?->reason);
    }

    #[DataProvider('boundedFailureProvider')]
    public function testPostClaimFailuresBecomeBoundedFailedTerminalOutcome(string $kind): void
    {
        $capability = $this->capability();
        $ledger = new EntrypointLedger(ExecutionClaimResult::accepted($this->record($capability)));
        $factory = new EntrypointFactory(new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), $kind === 'factory' ? new RuntimeException() : null, $kind === 'runtime' ? 'throw' : null, $kind === 'non-executable');
        $resolved = false;
        $result = $this->entrypoint($ledger, $factory, $resolved, [10, 20])->invoke($capability->encode(), 20);
        self::assertSame(ExecutionWorkerInvocationResult::POST_CLAIM_FAILED, $result->status);
        self::assertSame(ExecutionOutcome::FAILED, $result->outcome?->status);
        self::assertSame(['claim', 'terminal'], $ledger->events);
    }

    public static function boundedFailureProvider(): array
    {
        return [['factory'], ['runtime'], ['non-executable']];
    }

    #[DataProvider('terminalProvider')]
    public function testEveryNonAcceptedTerminalResultIsDistinctAndNotRetried(string $status, string $expected): void
    {
        $capability = $this->capability();
        $ledger = new EntrypointLedger(ExecutionClaimResult::accepted($this->record($capability)), false, $this->terminalResult($status));
        $result = $this->entrypoint($ledger, new EntrypointFactory(new ExecutionOutcome(ExecutionOutcome::SUCCEEDED)))->invoke($capability->encode(), 20);
        self::assertSame($expected, $result->status);
        self::assertSame(['claim', 'terminal'], $ledger->events);
        self::assertSame(1, $ledger->terminalCalls);
    }

    public static function terminalProvider(): array
    {
        return [[ExecutionTerminalResult::MISSING, ExecutionWorkerInvocationResult::TERMINAL_MISSING], [ExecutionTerminalResult::STALE_FENCED, ExecutionWorkerInvocationResult::TERMINAL_STALE_FENCED], [ExecutionTerminalResult::ALREADY_TERMINAL, ExecutionWorkerInvocationResult::TERMINAL_ALREADY_TERMINAL], [ExecutionTerminalResult::REJECTED, ExecutionWorkerInvocationResult::TERMINAL_REJECTED]];
    }

    public function testAlreadyTerminalCarriesTheDurableOutcome(): void
    {
        $capability = $this->capability();
        $ledger = new EntrypointLedger(ExecutionClaimResult::accepted($this->record($capability)), false, ExecutionTerminalResult::alreadyTerminal());
        $ledger->terminalObservation = $this->record($capability, state: ExecutionOutcome::SUCCEEDED, outcome: new ExecutionOutcome(ExecutionOutcome::SUCCEEDED));

        $result = $this->entrypoint($ledger, new EntrypointFactory(new ExecutionOutcome(ExecutionOutcome::SUCCEEDED)))->invoke($capability->encode(), 20);

        self::assertSame(ExecutionWorkerInvocationResult::TERMINAL_ALREADY_TERMINAL, $result->status);
        self::assertSame(ExecutionOutcome::SUCCEEDED, $result->outcome?->status);
    }

    public function testThrownTerminalWriteIsAmbiguousAndNotRetried(): void
    {
        $ledger = new EntrypointLedger(ExecutionClaimResult::accepted($this->record($this->capability())), false, null, true);
        $result = $this->entrypoint($ledger, new EntrypointFactory(new ExecutionOutcome(ExecutionOutcome::SUCCEEDED)))->invoke($this->capability()->encode(), 20);
        self::assertSame(ExecutionWorkerInvocationResult::TERMINAL_AMBIGUOUS, $result->status);
        self::assertSame(1, $ledger->terminalCalls);
    }

    private function terminalResult(string $status): ExecutionTerminalResult
    {
        return match ($status) {
            ExecutionTerminalResult::MISSING => ExecutionTerminalResult::missing(),
            ExecutionTerminalResult::STALE_FENCED => ExecutionTerminalResult::staleFenced(),
            ExecutionTerminalResult::ALREADY_TERMINAL => ExecutionTerminalResult::alreadyTerminal(),
            default => ExecutionTerminalResult::rejected('denied'),
        };
    }

    private function capability(): ExecutionWorkerCapability
    {
        return new ExecutionWorkerCapability(new ExecutionOccurrenceKey('schedule', 'task', 'occurrence'), 'attempt', 'run-1', 'execution-1', 3, 'fence-3', 'manifest-v1', hash('sha256', PreparedConfiguration::canonical($this->policy())), 'ledger://allow-listed', 'runtime://one', 'source-revision', $this->policy());
    }

    private function policy(): array
    {
        return ['format' => 3, 'source' => [null, null], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => null, 'metadata' => [], 'schedule' => 'schedule', 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]];
    }

    private function record(ExecutionWorkerCapability $capability, string $configurationFingerprint = 'manifest-v1', string $taskVersion = '', string $state = 'launch-required', ?ExecutionOutcome $outcome = null): ExecutionLedgerRecord
    {
        $taskVersion = $taskVersion === '' ? $capability->taskVersion : $taskVersion;
        $snapshot = $taskVersion === $capability->taskVersion ? $capability->policy : array_replace($capability->policy, ['task' => array_replace($capability->policy['task'], ['metadata' => ['version' => $taskVersion]])]);
        return new ExecutionLedgerRecord($capability->occurrence, new ExecutionIdentity('schedule', 'task', 'occurrence', 'attempt', 'run-1', 'execution-1'), 1, new ExecutionFence(3, 'fence-3'), $state, 'dispatch', $configurationFingerprint, $snapshot, 1, 1, $outcome);
    }

    private function entrypoint(EntrypointLedger $ledger, ExecutionWorkerRuntimeFactoryInterface $factory, bool &$resolved = false, array $times = [10, 20]): ExecutionWorkerEntrypoint
    {
        $locator = new class ($ledger, $resolved) implements ExecutionLedgerLocatorInterface {
            public function __construct(private readonly ExecutionLedgerInterface $ledger, private bool &$resolved) {}

            public function resolve(string $locator): ExecutionLedgerInterface
            {
                $this->resolved = true;
                return $this->ledger;
            }
        };
        return new ExecutionWorkerEntrypoint($locator, $factory, new EntrypointClock($times));
    }
}

final class EntrypointClock implements ClockInterface
{
    public function __construct(private array $times) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . (array_shift($this->times) ?? 0));
    }
}

final class EntrypointFactory implements ExecutionWorkerRuntimeFactoryInterface
{
    public bool $called = false;

    public function __construct(private readonly ExecutionOutcome $outcome, private readonly ?Throwable $failure = null, private readonly ?string $runtimeFailure = null, private readonly bool $nonExecutable = false) {}

    public function create(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $claimedRecord): ExecutionWorkerRuntimeInterface
    {
        $this->called = true;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->nonExecutable) {
            return new stdClass();
        }
        $failure = $this->runtimeFailure;
        return new class ($this->outcome, $failure) implements ExecutionWorkerRuntimeInterface {
            public function __construct(private readonly ExecutionOutcome $outcome, private readonly ?string $failure) {}

            public function execute(): ExecutionOutcome
            {
                if ($this->failure !== null) {
                    throw new RuntimeException('execute failed');
                } return $this->outcome;
            }
        };
    }
}

final class EntrypointLedger implements ExecutionLedgerInterface
{
    public array $events = [];
    public ?ExecutionLedgerRecord $observedRecord = null;
    public ?ExecutionLedgerRecord $terminalObservation = null;
    public int $observeCalls = 0;
    public array $claimArgs = [];
    public array $terminalArgs = [];
    public ?ExecutionFence $terminalFence = null;
    public ?ExecutionOutcome $terminalOutcome = null;
    public ?ExecutionOccurrenceKey $terminalOccurrence = null;
    public int $terminalCalls = 0;

    public function __construct(public ExecutionClaimResult $claimResult, private readonly bool $claimThrows = false, private readonly ?ExecutionTerminalResult $terminalResult = null, private readonly bool $terminalThrows = false)
    {
        $this->observedRecord = $claimResult->record;
    }

    public function admit(ExecutionLedgerRecord $record): ExecutionAdmission
    {
        throw new LogicException();
    }

    public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
    {
        $this->observeCalls++;
        return $this->observeCalls > 1 ? ($this->terminalObservation ?? $this->observedRecord) : $this->observedRecord;
    }

    public function hasTaskHistory(string $scheduleKey, string $taskKey): bool
    {
        return false;
    }

    public function claim(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, int $at, ?int $claimLeaseDeadline): ExecutionClaimResult
    {
        $this->events[] = 'claim';
        $this->claimArgs = [$at, $claimLeaseDeadline];
        if ($this->claimThrows) {
            throw new RuntimeException();
        } return $this->claimResult;
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
        $this->events[] = 'terminal';
        $this->terminalCalls++;
        $this->terminalArgs = [$at, $outcome->status];
        $this->terminalFence = $f;
        $this->terminalOutcome = $outcome;
        $this->terminalOccurrence = $o;
        if ($this->terminalThrows) {
            throw new RuntimeException();
        } return $this->terminalResult ?? ExecutionTerminalResult::accepted(new ExecutionLedgerRecord($o, new ExecutionIdentity('schedule', 'task', 'occurrence', 'attempt', 'run-1', 'execution-1'), 1, $f, $outcome->status, 'dispatch', 'manifest-v1', $this->observedRecord?->taskSnapshot ?? [], 1, 1, $outcome));
    }
}
