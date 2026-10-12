<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Executor;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Yiisoft\Schedule\Config\TimeoutPolicy;
use LogicException;
use RuntimeException;
use Yiisoft\Schedule\Executor\{ExecutionContext, ExecutorInterface, ShellCommand, ShellCommandExecutor, ShellCommandSpecification, ShellVariableOverrides};
use Yiisoft\Schedule\Integration\Async\{ExecutionOutcome, MonotonicClockInterface, PollWaiterInterface, ShellCommandRuntime, ShellCommandRuntimeFactoryInterface};
use Yiisoft\Schedule\Integration\Async\Process\{ProcessHandleInterface, ProcessLaunchRequest, ProcessLaunchResult, ProcessLauncherInterface, ProcessResult};
use Yiisoft\Schedule\Executor\CallableCommand;

final class ShellCommandExecutorTest extends TestCase
{
    public function testCanonicalExecutorHasOnlyTheRuntimeFactorySeam(): void
    {
        self::assertFalse(class_exists('Yiisoft\\Schedule\\Executor\\ShellExecutor'));
        $reflection = new ReflectionClass(ShellCommandExecutor::class);
        self::assertTrue($reflection->implementsInterface(ExecutorInterface::class));
        self::assertCount(1, $reflection->getConstructor()?->getParameters() ?? []);
        self::assertSame(ShellCommandRuntimeFactoryInterface::class, (string) $reflection->getConstructor()->getParameters()[0]->getType());
        $publicMethods = get_class_methods(ShellCommandExecutor::class);
        self::assertNotContains('poll', $publicMethods);
        self::assertNotContains('launch', $publicMethods);

        $factory = new ReflectionClass(ShellCommandRuntimeFactoryInterface::class);
        self::assertTrue($factory->isInterface());
        $create = $factory->getMethod('create');
        self::assertCount(2, $create->getParameters());
        self::assertSame('command', $create->getParameters()[0]->getName());
        self::assertSame(ShellCommandSpecification::class, (string) $create->getParameters()[0]->getType());
        self::assertSame('timeout', $create->getParameters()[1]->getName());
        self::assertSame(TimeoutPolicy::class, (string) $create->getParameters()[1]->getType());
        self::assertSame(ShellCommandRuntime::class, (string) $create->getReturnType());
    }

    public function testContextAwareExecutionDelegatesCommandContextAndTimeoutToFreshRuntime(): void
    {
        $created = [];
        $launches = [];
        $factory = new class ($created, $launches) implements ShellCommandRuntimeFactoryInterface {
            public function __construct(private array &$created, private array &$launches) {}

            public function create(ShellCommandSpecification $command, TimeoutPolicy $timeout): ShellCommandRuntime
            {
                $this->created[] = [$command, $timeout];
                return new ShellCommandRuntime(
                    $command,
                    new class ($this->launches) implements ProcessLauncherInterface {
                        public function __construct(private array &$launches) {}

                        public function launch(ProcessLaunchRequest $request): ProcessLaunchResult
                        {
                            $this->launches[] = $request;
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
                            }, 10.0);
                        }
                    },
                    $timeout,
                    new ShellVariableOverrides(),
                    ['TOKEN' => 'resolved'],
                    null,
                    new class implements MonotonicClockInterface {
                        public function now(): float
                        {
                            return 10.0;
                        }
                    },
                    new class implements PollWaiterInterface {
                        public function wait(float $seconds): void {}
                    },
                );
            }
        };
        $command = new ShellCommand('/bin/echo', ['literal payload', '${TOKEN}'], ['TOKEN' => 'resolved']);
        $context = new ExecutionContext(
            $command,
            new TimeoutPolicy(2.0, 4.0),
            ['payload' => 'preserved'],
            ['metadata' => 'preserved'],
        );

        self::assertSame($command, $context->command);
        self::assertSame(['payload' => 'preserved'], $context->payload);
        self::assertSame('preserved', $context->metadata['metadata']);

        $executor = new ShellCommandExecutor($factory);
        $outcome = $executor->execute($context);

        self::assertSame(ExecutionOutcome::SUCCEEDED, $outcome->status);
        self::assertCount(1, $created);
        self::assertEquals($command->specification(), $created[0][0]);
        self::assertSame(2.0, $created[0][1]->softSeconds);
        self::assertSame(4.0, $created[0][1]->hardSeconds);
        self::assertCount(1, $launches);
        self::assertSame('/bin/echo', $launches[0]->executable);
        self::assertSame(['literal payload', 'resolved'], $launches[0]->argv);
        self::assertSame(['TOKEN' => 'resolved'], $launches[0]->environment);
    }

    public function testRuntimeFactoryFailureMapsToSafeFailedOutcome(): void
    {
        $factory = new class implements ShellCommandRuntimeFactoryInterface {
            public function create(ShellCommandSpecification $command, TimeoutPolicy $timeout): ShellCommandRuntime
            {
                throw new RuntimeException('secret process detail');
            }
        };
        $context = new ExecutionContext(new ShellCommand('/bin/true'));

        $outcome = (new ShellCommandExecutor($factory))->execute($context);

        self::assertSame(ExecutionOutcome::FAILED, $outcome->status);
        self::assertSame('shell.execution_failed', $outcome->reason);
        self::assertStringNotContainsString('secret process detail', (string) $outcome->reason);
    }

    public function testInvalidCommandIsRejectedBeforeRuntimeCreation(): void
    {
        $factory = new class implements ShellCommandRuntimeFactoryInterface {
            public function create(ShellCommandSpecification $command, TimeoutPolicy $timeout): ShellCommandRuntime
            {
                throw new LogicException('must not be called');
            }
        };

        $this->expectException(InvalidArgumentException::class);
        (new ShellCommandExecutor($factory))->execute(new ExecutionContext(new CallableCommand(static fn(): string => 'invalid')));
    }
}
