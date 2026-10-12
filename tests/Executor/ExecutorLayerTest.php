<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Executor;

use PHPUnit\Framework\TestCase;
use Yiisoft\Schedule\Callable\ContainerProvider;
use Yiisoft\Schedule\Executor\{CallableCommand, CallableExecutor, ExecutionContext};
use Yiisoft\Schedule\Integration\Async\ExecutionOutcome;
use Yiisoft\Schedule\Tests\Support\StubContainer;
use InvalidArgumentException;

final class ExecutorLayerTest extends TestCase
{
    public function testDirectCallableInjectsDependencyAndUsesNamedArguments(): void
    {
        $executor = new CallableExecutor(new ContainerProvider(new StubContainer([
            ExecutorDependency::class => new class implements ExecutorDependency {
                public function prefix(string $value): string
                {
                    return 'done:' . $value;
                }
            },
        ])));
        $command = new CallableCommand(static fn(ExecutorDependency $dependency, string $value): string => $dependency->prefix($value), ['value' => 'value']);

        $outcome = $executor->execute(new ExecutionContext($command));
        self::assertSame(ExecutionOutcome::SUCCEEDED, $outcome->status);
        self::assertSame('done:value', $outcome->reason);
    }

    /** @dataProvider scalarResultProvider */
    public function testScalarResultsAreSuccessful(string|bool $result, ?string $reason): void
    {
        $command = new CallableCommand(static fn(): string|bool => $result);
        $outcome = (new CallableExecutor(new ContainerProvider(new StubContainer())))->execute(new ExecutionContext($command));
        self::assertSame(ExecutionOutcome::SUCCEEDED, $outcome->status);
        self::assertSame($reason, $outcome->reason);
    }

    public static function scalarResultProvider(): array
    {
        return [[false, '0'], ['', null]];
    }

    public function testContainerResolutionIsLazyAndOutcomeIsNotWrapped(): void
    {
        $resolved = false;
        $outcome = new ExecutionOutcome(ExecutionOutcome::FAILED, 'business.failed');
        $executor = new CallableExecutor(new ContainerProvider(static function () use (&$resolved): StubContainer {
            $resolved = true;
            return new StubContainer();
        }));
        $command = new CallableCommand(static fn(): ExecutionOutcome => $outcome);
        self::assertFalse($resolved);
        self::assertSame($outcome, $executor->execute(new ExecutionContext($command)));
        self::assertTrue($resolved);
    }

    public function testInvalidPayloadIsBoundedFailure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CallableCommand(static fn(): string => 'effect', ['legacy']);
    }

    public function testGuardFailurePreventsInvocationAndContainerResolution(): void
    {
        $effects = 0;
        $providerCalls = 0;
        $executor = new CallableExecutor(new ContainerProvider(static function () use (&$providerCalls): StubContainer {
            ++$providerCalls;
            return new StubContainer();
        }), static fn(): bool => false);
        $command = new CallableCommand(static function () use (&$effects): string {
            ++$effects;
            return 'bad';
        });
        self::assertSame(ExecutionOutcome::FAILED, $executor->execute(new ExecutionContext($command))->status);
        self::assertSame(0, $effects);
        self::assertSame(0, $providerCalls);
    }

    public function testGuardFailureAfterInvocationReturnsBoundedFailure(): void
    {
        $guardCalls = 0;
        $invocations = 0;
        $executor = new CallableExecutor(new ContainerProvider(new StubContainer()), static function () use (&$guardCalls): bool {
            return ++$guardCalls < 4;
        });
        $command = new CallableCommand(static function () use (&$invocations): string {
            ++$invocations;
            return 'success';
        });
        $outcome = $executor->execute(new ExecutionContext($command));
        self::assertSame(ExecutionOutcome::FAILED, $outcome->status);
        self::assertSame('callable.execution_failed', $outcome->reason);
        self::assertSame(1, $invocations);
        self::assertSame(4, $guardCalls);
    }
}

interface ExecutorDependency
{
    public function prefix(string $value): string;
}
