<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Binary;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use ReflectionNamedType;
use Yiisoft\Scheduler\Binary\{WorkerAdapter, WorkerArgumentParser, WorkerInvocation};
use Yiisoft\Scheduler\Executor\ShellVariableOverrides;
use DateTimeImmutable;
use Yiisoft\Scheduler\Integration\Async\{ExecutionClaimResult, ExecutionFence, ExecutionIdentity, ExecutionLedgerCommandInterface, ExecutionLedgerQueryInterface, ExecutionLedgerLocatorInterface, ExecutionLedgerRecord, ExecutionOccurrenceKey, ExecutionOutcome, ExecutionTerminalResult, ExecutionWorkerCapability, ExecutionWorkerEntrypoint, ExecutionWorkerRuntimeFactoryInterface, ExecutionWorkerRuntimeInterface};

final class WorkerAdapterTest extends TestCase
{
    public function testWorkerArgumentsAreStrictAndTyped(): void
    {
        $result = (new WorkerArgumentParser())->parse([
            'run', 'send', '--config', '/tmp/config.php', '--source', 'source-id', '--state', '/tmp/state',
        ]);

        self::assertInstanceOf(WorkerInvocation::class, $result);
        self::assertSame('send', $result->task);
        self::assertSame('/tmp/config.php', $result->config);
        self::assertSame('source-id', $result->source);
        self::assertSame('/tmp/state', $result->state);
        self::assertInstanceOf(ShellVariableOverrides::class, $result->overrides);
        self::assertSame([], $result->overrides->values);
    }

    public function testWorkerInvocationIsImmutable(): void
    {
        $result = (new WorkerArgumentParser())->parse(['run', 'send', '--config', 'x', '--source', 's', '--state', 'r']);
        self::assertInstanceOf(WorkerInvocation::class, $result);
        $type = new ReflectionClass($result);
        self::assertSame('Yiisoft\\Scheduler\\Binary\\WorkerInvocation', $type->getName());
        foreach (['task', 'config', 'source', 'state', 'overrides'] as $property) {
            self::assertTrue($type->getProperty($property)->isReadOnly());
        }
    }

    public function testLaunchMetadataIsAbsentUntilInvocationThenContainsTypedMetadata(): void
    {
        $occurrence = new ExecutionOccurrenceKey('send', 'occurrence');
        $policy = [
            'schema' => 'dev',
            'source' => ['source', 'source-revision'],
            'task' => [
                'id' => 'send', 'mode' => 'async', 'timeout' => null, 'recoveryMaxAge' => 180.0, 'metadata' => [],
                'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60]],
                'overlap' => 'allow', 'missed' => ['skip', null],
            ],
        ];
        $capability = new ExecutionWorkerCapability(
            $occurrence,
            'attempt',
            'run',
            'execution',
            1,
            'fence',
            'configuration',
            'task-version',
            'ledger://worker',
            'runtime://worker',
            'source-revision',
            $policy,
        );
        $record = new ExecutionLedgerRecord(
            $occurrence,
            new ExecutionIdentity('send', 'occurrence', 'attempt', 'run', 'execution'),
            1,
            new ExecutionFence(1, 'fence'),
            'launch-required',
            'configuration',
            $policy,
            sourceIdentity: 'source',
            sourceRevision: 'source-revision',
        );
        $ledger = $this->createMockForIntersectionOfInterfaces([ExecutionLedgerCommandInterface::class, ExecutionLedgerQueryInterface::class]);
        $ledger->method('observe')->willReturn($record);
        $ledger->method('claim')->willReturn(ExecutionClaimResult::accepted($record));
        $ledger->method('recordOutcome')->willReturn(ExecutionTerminalResult::accepted($record));
        $locator = $this->createMock(ExecutionLedgerLocatorInterface::class);
        $locator->method('resolve')->willReturn($ledger);
        $runtime = $this->createMock(ExecutionWorkerRuntimeInterface::class);
        $runtime->method('execute')->willReturn(new ExecutionOutcome(ExecutionOutcome::SUCCEEDED));
        $factory = $this->createMock(ExecutionWorkerRuntimeFactoryInterface::class);
        $factory->method('create')->willReturn($runtime);
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@1');
            }
        };
        $adapter = new WorkerAdapter(new ExecutionWorkerEntrypoint($locator, $factory, $clock), $locator);

        self::assertNull($adapter->launchMetadata());

        $adapter->invoke(['run', 'send', '--config', 'config.php', '--source', 'source', '--state', 'state'], $capability->encode());

        $metadata = $adapter->launchMetadata();
        self::assertInstanceOf(WorkerInvocation::class, $metadata);
        self::assertSame('send', $metadata->task);
        self::assertSame('config.php', $metadata->config);
        self::assertSame('source', $metadata->source);
        self::assertSame('state', $metadata->state);
        self::assertSame([], $metadata->overrides->values);
    }

    public function testLaunchMetadataHasNullableWorkerInvocationReturnAndTypedProperties(): void
    {
        $returnType = (new ReflectionClass(WorkerAdapter::class))->getMethod('launchMetadata')->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertTrue($returnType->allowsNull());
        self::assertSame(WorkerInvocation::class, $returnType->getName());

        $properties = [
            'task' => 'string',
            'config' => 'string',
            'source' => 'string',
            'state' => 'string',
            'overrides' => ShellVariableOverrides::class,
        ];
        $invocation = new ReflectionClass(WorkerInvocation::class);
        self::assertSame(array_keys($properties), array_map(
            static fn($property): string => $property->getName(),
            $invocation->getProperties(),
        ));

        foreach ($properties as $name => $expectedType) {
            $type = $invocation->getProperty($name)->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type);
            self::assertFalse($type->allowsNull());
            self::assertSame($expectedType, $type->getName());
        }
    }

    public function testEphemeralVariablesSupportBothForms(): void
    {
        $result = (new WorkerArgumentParser())->parse([
            'run', 'send', '--config', 'x', '--source', 's', '--state', 'r',
            '--var', 'TOKEN=one', '--var=SECOND=two',
        ]);

        self::assertInstanceOf(WorkerInvocation::class, $result);
        self::assertSame(['TOKEN' => 'one', 'SECOND' => 'two'], $result->overrides->values);
    }

    #[DataProvider('invalidVariables')]
    public function testMalformedVariablesAreRejected(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WorkerArgumentParser())->parse(array_merge(
            ['run', 'send', '--config', 'x', '--source', 's', '--state', 'r'],
            $arguments,
        ));
    }

    public static function invalidVariables(): iterable
    {
        yield [['--var', 'TOKEN']];
        yield [['--var=1TOKEN=value']];
        yield [['--var', 'TOKEN=one', '--var', 'TOKEN=two']];
        yield [['--var', 'TOKEN=' . str_repeat('x', 65537)]];
    }

    #[DataProvider('invalidArguments')]
    public function testPlannerAndMalformedArgumentsAreRejected(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WorkerArgumentParser())->parse($arguments);
    }

    public static function invalidArguments(): iterable
    {
        yield [['--config', 'x', 'run', 'task']];
        yield [['run', 'task', '--config', 'x', '--config', 'y', '--source', 's', '--state', 'r']];
        yield [['run', 'task', '--config', 'x', '--source', 's', '--state', 'r', 'extra']];
        yield [['run', 'task', '--config', '', '--source', 's', '--state', 'r']];
        yield [['run', 'task', '--config', 'x', '--source', '', '--state', 'r']];
        yield [['run', 'task', '--config', 'x', '--source', 's', '--state', '']];
        yield [['run', 'task', '--config', 'x', '--source', 's']];
        yield [['run', 'task', '--config', 'x', '--state', 'r']];
        yield [['run', 'task', '--source', 's', '--state', 'r']];
        yield [['run', 'task', '--once', '--config', 'x', '--source', 's', '--state', 'r']];
        yield [['run', 'task', '--profile', 'batch', '--config', 'x', '--source', 's', '--state', 'r']];
        yield [['run', 'task', '--bootstrap', '/tmp/bootstrap.php', '--config', 'x', '--source', 's', '--state', 'r']];
    }
}
