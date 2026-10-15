<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\{PhpConfigurationSource, PreparedConfiguration, RuntimeConfigurationSourceInterface, TimeoutPolicy};
use Yiisoft\Scheduler\Executor\{CallableCommand, CommandExecutorMap, CommandExecutorRuntimeFactory, ExecutionContext, ExecutorInterface, ShellCommandExecutor, ShellCommandSpecification, ShellVariableOverrides};
use Yiisoft\Scheduler\Integration\Async\{ExecutionFence, ExecutionIdentity, ExecutionLedgerRecord, ExecutionOccurrenceKey, ExecutionOutcome, ExecutionWorkerCapability, MonotonicClockInterface, PollWaiterInterface, PreparedConfigurationExecutionWorkerRuntimeFactory, ShellCommandRuntime, ShellCommandRuntimeFactoryInterface};
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessHandleInterface, ProcessLaunchRequest, ProcessLaunchResult, ProcessLauncherInterface, ProcessResult};
use Yiisoft\Scheduler\Tests\Support\{RuntimeCallableProbe, TestCommandExecutorRuntimeFactory};
use Yiisoft\Scheduler\Config\TaskDefinition;
use Yiisoft\Scheduler\Executor\ShellCommand;
use RuntimeException;

final class PreparedConfigurationExecutionWorkerRuntimeFactoryTest extends TestCase
{
    private MutableTestConfigurationSource $source;

    public function testServiceReferenceComesFromPortableTaskPayload(): void
    {
        RuntimeCallableProbe::$service = null;
        [$factory, $capability, $record] = $this->factory(['command' => 'callable:mailer', 'payload' => 'mailer']);
        (new PreparedConfigurationExecutionWorkerRuntimeFactory($this->source, new TestCommandExecutorRuntimeFactory()))->create($capability, $record)->execute();
        self::assertSame('mailer', RuntimeCallableProbe::$service);
    }

    public function testCustomCallableExecutorIsNotReplacedByWorkerGuard(): void
    {
        $executor = new class implements ExecutorInterface {
            public function execute(ExecutionContext $context): ExecutionOutcome
            {
                return new ExecutionOutcome('succeeded', 'custom-callable');
            }
        };
        [$factory, $capability, $record] = $this->factory(
            ['command' => 'callable:fixture'],
            executors: new CommandExecutorMap([CallableCommand::class => $executor]),
        );

        self::assertSame('custom-callable', $factory->create($capability, $record)->execute()->reason);
    }

    public function testCustomShellExecutorIsNotReplacedByWorkerShellRuntime(): void
    {
        $executor = new class implements ExecutorInterface {
            public function execute(ExecutionContext $context): ExecutionOutcome
            {
                return new ExecutionOutcome('succeeded', 'custom-shell');
            }
        };
        $shellRuntimeFactory = new class implements ShellCommandRuntimeFactoryInterface {
            public function create(ShellCommandSpecification $command, TimeoutPolicy $timeout): ShellCommandRuntime
            {
                throw new RuntimeException('The configured shell executor was replaced.');
            }
        };
        [$factory, $capability, $record] = $this->factory(
            ['command' => 'shell:task', 'shell' => true],
            executors: new CommandExecutorMap([
                ShellCommand::class => $executor,
            ]),
        );
        $factory = new PreparedConfigurationExecutionWorkerRuntimeFactory($this->source, new TestCommandExecutorRuntimeFactory(new CommandExecutorMap([
            ShellCommand::class => $executor,
        ])), $shellRuntimeFactory);

        self::assertSame('custom-shell', $factory->create($capability, $record)->execute()->reason);
    }

    public function testDelayedPreparationPreservesConfiguredCommandTimeoutWithoutBudgetMetadata(): void
    {
        /** @var array<array-key, mixed> $seen */
        $seen = [];
        $runtimeFactory = new class ($seen) implements ShellCommandRuntimeFactoryInterface {
            public function __construct(private array &$seen) {}

            public function create(ShellCommandSpecification $command, TimeoutPolicy $timeout): ShellCommandRuntime
            {
                $this->seen = ['soft' => $timeout->softSeconds, 'hard' => $timeout->hardSeconds];
                return new ShellCommandRuntime(
                    $command,
                    new class implements ProcessLauncherInterface {
                        public function launch(ProcessLaunchRequest $request): ProcessLaunchResult
                        {
                            return new ProcessLaunchResult(new class implements ProcessHandleInterface {
                                public function isRunning(): bool
                                {
                                    return false;
                                }

                                public function isQuiescent(): bool
                                {
                                    return true;
                                }

                                public function poll(): ?ProcessResult
                                {
                                    return new ProcessResult(0);
                                }

                                public function terminateSoft(): void {}

                                public function terminateHard(): void {}
                            }, 0.0);
                        }
                    },
                    $timeout,
                    new ShellVariableOverrides(),
                    [],
                    null,
                    new class implements MonotonicClockInterface {
                        public function now(): float
                        {
                            return 0.0;
                        }
                    },
                    new class implements PollWaiterInterface {
                        public function wait(float $seconds): void {}
                    },
                );
            }
        };
        $registry = new CommandExecutorMap([ShellCommand::class => new ShellCommandExecutor($runtimeFactory)]);
        $clock = new class {
            public float $time = 0.0;
        };
        [$factory, $capability, $record] = $this->factory(
            ['command' => 'shell:task', 'shell' => true, 'timeout' => ['soft' => 5.0, 'hard' => 8.0]],
            null,
            null,
            $registry,
            static function () use ($clock): void {
                $clock->time += 100.0;
            },
        );

        self::assertSame('succeeded', $factory->create($capability, $record)->execute()->status);
        self::assertSame(['soft' => 5.0, 'hard' => 8.0], $seen);
        self::assertSame(200.0, $clock->time);
    }

    public function testSkipOverlapTaskAcceptsItsConfiguredFingerprint(): void
    {
        [, $capability, $record] = $this->factory(['command' => 'callable:fixture', 'payload' => 'fixture', 'overlap' => 'skip']);
        $configuration = $this->source->prepare(new TestCommandExecutorRuntimeFactory());
        $expected = $configuration->taskFingerprint($configuration->task('task'));

        (new PreparedConfigurationExecutionWorkerRuntimeFactory($this->source, new TestCommandExecutorRuntimeFactory()))->create($capability, $record);
        self::assertSame($expected, $capability->taskVersion);
        self::assertSame($configuration->taskPolicyProjection($configuration->task('task')), $record->taskSnapshot);
    }

    public function testSynchronousTaskIsRejectedBeforeExecutorInvocation(): void
    {
        RuntimeCallableProbe::$service = null;
        [$factory, $capability, $record] = $this->factory(['command' => 'callable:fixture', 'sync' => true]);

        try {
            $factory->create($capability, $record);
            self::fail('A synchronous task must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertNull(RuntimeCallableProbe::$service);
        }
    }

    public function testFenceMismatchIsRejectedBeforeExecutorInvocation(): void
    {
        RuntimeCallableProbe::$service = null;
        [$factory, $capability, $record] = $this->factory(['command' => 'callable:fixture']);
        $forged = new ExecutionLedgerRecord(
            $record->occurrence,
            $record->correlation,
            $record->attempt,
            new ExecutionFence(2, 'different-fence'),
            $record->state,
            $record->launchIntent,
            $record->configurationFingerprint,
            $record->taskSnapshot,
            $record->createdAt,
            $record->updatedAt,
            $record->outcome,
            metadata: $record->metadata,
        );

        try {
            $factory->create($capability, $forged);
            self::fail('A fence mismatch must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertNull(RuntimeCallableProbe::$service);
        }
    }

    public function testSelectedTaskValidationDoesNotReadProfileMetadata(): void
    {
        /** @var array<array-key, mixed> $seenMetadata */
        $seenMetadata = [];
        $registry = new CommandExecutorMap([ShellCommand::class => new class ($seenMetadata) implements ExecutorInterface {
            public function __construct(private array &$seenMetadata) {}

            public function execute(ExecutionContext $context): ExecutionOutcome
            {
                $this->seenMetadata = $context->metadata;
                return new ExecutionOutcome('succeeded');
            }
        }]);
        [$factory, $capability, $record] = $this->factory([
            'command' => 'shell:task',
            'shell' => true,
            'metadata' => ['worker-only' => 'not part of task identity'],
        ], null, null, $registry);

        $runtime = $factory->create($capability, $record);
        self::assertSame('succeeded', $runtime->execute()->status);
        self::assertSame([], $seenMetadata);
    }

    public function testSkipOverlapTaskRejectsAllowFingerprint(): void
    {
        [, $capability, $record] = $this->factory(['command' => 'callable:fixture', 'payload' => 'fixture', 'overlap' => 'skip']);
        $configuration = $this->source->prepare(new TestCommandExecutorRuntimeFactory());
        $task = $configuration->task('task');
        $allowTask = new TaskDefinition(
            $task->id,
            $task->command,
            $task->mode,
            $task->timeout,
            $task->metadata,
            $task->trigger,
            $task->missedOccurrences,
            'allow',
        );
        $rejectedFingerprint = $configuration->taskFingerprint($allowTask);
        self::assertNotSame($capability->taskVersion, $rejectedFingerprint);
        $forged = new ExecutionWorkerCapability($capability->occurrence, $capability->attemptId, $capability->runId, $capability->executionId, $capability->generation, $capability->fenceToken, $capability->configurationFingerprint, $rejectedFingerprint, $capability->ledgerLocator, $capability->runtimeLocator, $capability->sourceRevision, $capability->policy);
        $claimed = new ExecutionLedgerRecord(
            $record->occurrence,
            $record->correlation,
            $record->attempt,
            $record->fence,
            $record->state,
            $record->launchIntent,
            $record->configurationFingerprint,
            array_replace($capability->policy, ['task' => array_replace($capability->policy['task'], ['overlap' => 'allow'])]),
            $record->createdAt,
            $record->updatedAt,
            $record->outcome,
            metadata: $record->metadata,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Worker claim identity does not match its capability.');
        (new PreparedConfigurationExecutionWorkerRuntimeFactory($this->source, new TestCommandExecutorRuntimeFactory()))->create($forged, $claimed);
    }

    #[DataProvider('admittedSourceIdentityProvider')]
    public function testAdmittedSourceIdentityIsRequiredAndBoundedBeforeClaim(array $metadata): void
    {
        [$factory, $capability, $record] = $this->factory(['command' => 'callable:fixture'], null, $metadata);

        $this->expectException(InvalidArgumentException::class);
        $factory->validate($capability, $record);
    }

    public static function admittedSourceIdentityProvider(): array
    {
        return [
            'missing' => [['sourceRevision' => '__current__']],
            'malformed type' => [['sourceId' => ['source'], 'sourceRevision' => '__current__']],
            'empty' => [['sourceId' => '', 'sourceRevision' => '__current__']],
            'too long' => [['sourceId' => str_repeat('x', 4097), 'sourceRevision' => '__current__']],
        ];
    }

    #[DataProvider('admittedSourceIdentityProvider')]
    public function testClaimedSourceIdentityIsRequiredAndBoundedBeforePreparation(array $metadata): void
    {
        [$factory, $capability, $record] = $this->factory(['command' => 'callable:fixture'], null, $metadata);

        $this->expectException(InvalidArgumentException::class);
        $factory->create($capability, $record);
    }

    public function testAdmittedSourceRevisionBindsCapabilityBeforeClaim(): void
    {
        [$factory, $capability, $record] = $this->factory(['command' => 'callable:fixture'], 'forged-current', ['sourceId' => '__current__', 'sourceRevision' => 'old']);
        try {
            $factory->validate($capability, $record);
            self::fail('A capability revision forged against the admitted record must be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Worker source revisions do not match.', $e->getMessage());
        }
    }

    public function testAdmittedSourceRevisionIsUsedWhenCapabilityOmitsIt(): void
    {
        [$factory, , $record] = $this->factory(['command' => 'callable:fixture'], null, ['sourceId' => '__current__', 'sourceRevision' => 'old']);
        /** @var string $taskVersion */
        $taskVersion = hash('sha256', PreparedConfiguration::canonical($record->taskSnapshot));
        try {
            $factory->validate(new ExecutionWorkerCapability(
                $record->occurrence,
                'attempt',
                'run',
                'execution',
                1,
                'fence',
                $record->configurationFingerprint,
                $taskVersion,
                'ledger',
                'runtime',
                $record->metadata['sourceRevision'] ?? 'source-revision',
                $record->taskSnapshot,
            ), $record);
            self::fail('The admitted source revision must be checked when capability omits it.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Worker source revisions do not match.', $e->getMessage());
        }
    }

    #[DataProvider('claimedSourceRevisionProvider')]
    public function testClaimedSourceRevisionIsRevalidatedBeforeRuntimeCreation(mixed $claimedRevision): void
    {
        [$factory, $capability, $record] = $this->factory(
            ['command' => 'callable:fixture'],
            recordMetadata: $claimedRevision === '__missing__' ? ['sourceId' => '__current__'] : ['sourceId' => '__current__', 'sourceRevision' => $claimedRevision],
        );

        $this->expectException(InvalidArgumentException::class);
        $factory->create($capability, $record);
    }

    /** @return array<string, array{mixed}> */
    public static function claimedSourceRevisionProvider(): array
    {
        return [
            'missing' => ['__missing__'],
            'invalid type' => [['revision']],
            'empty' => [''],
            'too long' => [str_repeat('x', 513)],
            'different' => ['different-revision'],
        ];
    }

    public function testSourceIdentityMutationImmediatelyBeforeInvocationPreventsExecution(): void
    {
        [$factory, $capability, $record] = $this->factory(['command' => 'callable:fixture']);
        $runtime = $factory->create($capability, $record);
        RuntimeCallableProbe::$service = null;
        $this->source->sourceIdValue = 'mutated-source-id';

        try {
            $runtime->execute();
            self::fail('A source identity mutation must prevent invocation.');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Worker source identity changed before invocation.', $e->getMessage());
        }
        self::assertNull(RuntimeCallableProbe::$service);
    }

    public function testValidMismatchedSourceIdentityIsRejected(): void
    {
        [$factory, $capability, $record] = $this->factory(
            ['command' => 'callable:fixture'],
            null,
            ['sourceId' => 'another-valid-source', 'sourceRevision' => '__current__'],
        );

        $this->expectException(InvalidArgumentException::class);
        $factory->validate($capability, $record);
    }

    /** @return array{PreparedConfigurationExecutionWorkerRuntimeFactory, ExecutionWorkerCapability, ExecutionLedgerRecord} */
    private function factory(array $task, ?string $capabilityRevision = null, ?array $recordMetadata = null, ?CommandExecutorMap $executors = null, ?Closure $prepareDelay = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'schedule-prepared-');
        $metadata = var_export($task['metadata'] ?? [], true);
        $payload = var_export(['service' => $task['payload'] ?? 'fixture'], true);
        $overlap = var_export($task['overlap'] ?? 'allow', true);
        $mode = ($task['sync'] ?? false) === true ? '->sync()' : '';
        if (($task['shell'] ?? false) === true) {
            $commandSource = "new \\Yiisoft\\Scheduler\\Executor\\ShellCommand('/bin/fixture')";
        } else {
            $commandSource = "new \\Yiisoft\\Scheduler\\Executor\\CallableCommand(static function (mixed \$service = null, mixed \$value = null): \\Yiisoft\\Scheduler\\Integration\\Async\\ExecutionOutcome { \\Yiisoft\\Scheduler\\Tests\\Support\\RuntimeCallableProbe::\$service = \$service; return new \\Yiisoft\\Scheduler\\Integration\\Async\\ExecutionOutcome('succeeded'); }, {$payload})";
        }
        $timeout = isset($task['timeout']) ? '->timeout(' . var_export($task['timeout']['soft'], true) . ', ' . var_export($task['timeout']['hard'], true) . ')' : '';
        file_put_contents($path, "<?php return static function (): \\Yiisoft\\Scheduler\\Config\\SchedulerConfig { \$config = new \\Yiisoft\\Scheduler\\Config\\SchedulerConfig(); \$config->periodic('task', {$commandSource})->every(60){$mode}{$timeout}->overlap({$overlap}); return \$config; }; ");
        $this->source = new MutableTestConfigurationSource(new PhpConfigurationSource($path), $prepareDelay);
        $recordMetadata = $recordMetadata ?? ['sourceId' => $this->source->sourceId(), 'sourceRevision' => $this->source->revision()];
        if (($recordMetadata['sourceId'] ?? null) === '__current__') {
            $recordMetadata['sourceId'] = $this->source->sourceId();
        }
        if (($recordMetadata['sourceRevision'] ?? null) === '__current__') {
            $recordMetadata['sourceRevision'] = $this->source->revision();
        }
        $configuration = $this->source->prepare(new TestCommandExecutorRuntimeFactory($executors));
        $definition = $configuration->tasks['task'];
        $command = $definition->command;
        $occurrence = new ExecutionOccurrenceKey('task', 'occurrence');
        $policy = $configuration->taskPolicyProjection($definition);
        // Worker transport is always the admitted asynchronous policy shape; the selected
        // synchronous definition is rejected by the runtime factory after identity checks.
        if (($task['sync'] ?? false) === true) {
            $policy['task']['mode'] = 'async';
        }
        $taskVersion = hash('sha256', PreparedConfiguration::canonical($policy));
        $capability = new ExecutionWorkerCapability($occurrence, 'attempt', 'run', 'execution', 4, 'fence-4', $configuration->fingerprint(), $taskVersion, 'ledger', 'runtime', $capabilityRevision ?? $this->source->revision(), $policy);
        $record = new ExecutionLedgerRecord($occurrence, new ExecutionIdentity('task', 'occurrence', 'attempt', 'run', 'execution'), 1, new ExecutionFence(4, 'fence-4'), 'launch-required', 'dispatch', $capability->configurationFingerprint, $policy, metadata: $recordMetadata);
        return [new PreparedConfigurationExecutionWorkerRuntimeFactory($this->source, new TestCommandExecutorRuntimeFactory($executors)), $capability, $record];
    }
}

final class MutableTestConfigurationSource implements RuntimeConfigurationSourceInterface
{
    public string $sourceIdValue;

    public function __construct(private readonly PhpConfigurationSource $delegate, private readonly ?Closure $prepareDelay = null)
    {
        $this->sourceIdValue = $delegate->sourceId();
    }

    public function sourceId(): string
    {
        return $this->sourceIdValue;
    }

    public function revision(): string
    {
        return $this->delegate->revision();
    }

    public function prepare(CommandExecutorRuntimeFactory $runtimeFactory): PreparedConfiguration
    {
        if ($this->prepareDelay !== null) {
            ($this->prepareDelay)();
        }
        return $this->delegate->prepare($runtimeFactory)->withSource($this->sourceId(), $this->revision());
    }
}
