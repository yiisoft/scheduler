<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Process;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\PreparedConfiguration;
use Yiisoft\Scheduler\Integration\Async\WorkerLifecyclePolicy;
use Yiisoft\Scheduler\Executor\{PhpProcessConfiguration,ShellVariableOverrides};
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchDescriptor;
use Yiisoft\Scheduler\Integration\Async\ExecutionOccurrenceKey;
use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerCapability;
use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerLaunchRequest;
use Yiisoft\Scheduler\Integration\Async\MonotonicClockInterface;
use Yiisoft\Scheduler\Integration\Async\Process\SameFileExecutionWorkerProcessCommandBuilder;

use function putenv;

final class SameFileExecutionWorkerProcessCommandBuilderTest extends TestCase
{
    public function testDescriptorMarkersAreRoleSpecific(): void
    {
        $occurrence = new ExecutionOccurrenceKey('task', 'occurrence');
        $worker = new ExecutionLaunchDescriptor('execution', 1, 'nonce-worker', occurrence: $occurrence);
        $command = new ExecutionLaunchDescriptor(
            'execution',
            1,
            'nonce-command',
            role: ExecutionLaunchDescriptor::ROLE_COMMAND,
            rootDescriptor: $worker,
            occurrence: $occurrence,
        );

        self::assertSame(ExecutionLaunchDescriptor::WORKER_MARKER, $worker->markerName());
        self::assertSame(ExecutionLaunchDescriptor::COMMAND_MARKER, $command->markerName());
        self::assertNotSame($worker->marker(), $command->marker());
    }

    public function testCommandDescriptorRequiresMatchingWorkerBinding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExecutionLaunchDescriptor(
            'execution',
            2,
            'nonce-command',
            role: ExecutionLaunchDescriptor::ROLE_COMMAND,
            rootDescriptor: new ExecutionLaunchDescriptor('execution', 1, 'nonce-worker', occurrence: new ExecutionOccurrenceKey('task', 'root-occurrence')),
            occurrence: new ExecutionOccurrenceKey('task', 'occurrence'),
        );
    }

    public function testCommandDescriptorRejectsWorkerRootWithWrongScope(): void
    {
        $occurrence = new ExecutionOccurrenceKey('task', 'occurrence');
        $worker = new ExecutionLaunchDescriptor(
            'execution',
            1,
            'nonce-worker',
            scopeKind: 'other-scope',
            occurrence: $occurrence,
        );

        $this->expectException(InvalidArgumentException::class);
        new ExecutionLaunchDescriptor(
            'execution',
            1,
            'nonce-command',
            role: ExecutionLaunchDescriptor::ROLE_COMMAND,
            rootDescriptor: $worker,
            occurrence: $occurrence,
        );
    }

    public function testWorkerLaunchRequiresCapabilityOccurrenceBinding(): void
    {
        $request = $this->request();
        $this->expectException(InvalidArgumentException::class);
        new ExecutionWorkerLaunchRequest(
            $request->capability,
            descriptor: new ExecutionLaunchDescriptor(
                $request->capability->executionId,
                $request->capability->generation,
                occurrence: new ExecutionOccurrenceKey('task', 'different-occurrence'),
            ),
        );
    }

    public function testRejectsLegacyCapabilitySchemaMarker(): void
    {
        $encoded = $this->request()->capability->encode();
        $this->expectException(InvalidArgumentException::class);
        ExecutionWorkerCapability::decode(str_replace('"schema":"dev"', '"schema":"legacy"', $encoded));
    }

    public function testBuilderDoesNotTransportRemainingBudget(): void
    {
        $request = new ExecutionWorkerLaunchRequest(
            $this->request()->capability,
            new WorkerLifecyclePolicy(hardSeconds: 30),
            launchStartedAt: 0.0,
        );
        $builder = new SameFileExecutionWorkerProcessCommandBuilder(
            '/usr/bin/php',
            '/app/config.php',
            '/app/source.php',
            '/app/state',
            clock: new class implements MonotonicClockInterface {
                public function now(): float
                {
                    return 10.0;
                }
            },
        );

        $process = $builder->build($request);
        $capability = ExecutionWorkerCapability::decode($process->protocolInput);

        self::assertSame(
            ['run', 'task', '--config', '/app/config.php', '--source', '/app/source.php', '--state', '/app/state'],
            $process->argv,
        );
        self::assertSame($request->capability->toArray(), $capability->toArray());
        self::assertArrayNotHasKey('remainingBudget', $capability->toArray());
        self::assertStringNotContainsString('remainingBudget', $process->protocolInput);
    }

    public function testExplicitPhpConfigurationPrefixesWorkerScriptAndArguments(): void
    {
        $builder = new SameFileExecutionWorkerProcessCommandBuilder(
            '/app/scheduler.php',
            '/app/config.php',
            '/app/source.php',
            '/app/state',
            phpConfiguration: new PhpProcessConfiguration('/usr/bin/php81', ['-d', 'memory_limit=1G']),
        );

        $process = $builder->build($this->request());

        self::assertSame('/usr/bin/php81', $process->executable);
        self::assertSame(
            ['-d', 'memory_limit=1G', '/app/scheduler.php', 'run', 'task', '--config', '/app/config.php', '--source', '/app/source.php', '--state', '/app/state'],
            $process->argv,
        );
    }

    public function testExplicitEnvironmentIsPassedWithoutChangingWorkerContract(): void
    {
        $request = $this->request();
        $builder = new SameFileExecutionWorkerProcessCommandBuilder(
            '/usr/bin/php',
            '/app/config.php',
            '/app/source.php',
            '/app/state',
            null,
            new ShellVariableOverrides(['APP_MODE' => 'override']),
            environment: ['APP_MODE' => 'application', 'APP_SECRET' => 'in-memory'],
        );

        $process = $builder->build($request);

        self::assertSame(
            ['run', 'task', '--config', '/app/config.php', '--source', '/app/source.php', '--state', '/app/state', '--var', 'APP_MODE=override'],
            $process->argv,
        );
        self::assertSame(
            [
                'APP_MODE' => 'application',
                'APP_SECRET' => 'in-memory',
            ],
            $process->environment,
        );
        self::assertSame($request->capability->encode(), $process->protocolInput);
    }

    public function testInheritedEnvironmentIsPassedToWorker(): void
    {
        $name = 'YII_SCHEDULE_BUILDER_TEST_' . getmypid();
        putenv($name . '=inherited');

        try {
            $process = (new SameFileExecutionWorkerProcessCommandBuilder(
                '/usr/bin/php',
                '/app/config.php',
                '/app/source.php',
                '/app/state',
            ))->build($this->request());

            self::assertArrayHasKey($name, $process->environment);
            self::assertSame('inherited', $process->environment[$name]);
            self::assertSame(['run', 'task', '--config', '/app/config.php', '--source', '/app/source.php', '--state', '/app/state'], $process->argv);
        } finally {
            putenv($name);
        }
    }

    private function request(): ExecutionWorkerLaunchRequest
    {
        $policy = ['schema' => 'dev', 'source' => ['source', 'source-revision'], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => null, 'recoveryMaxAge' => 180.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]];
        return new ExecutionWorkerLaunchRequest(new ExecutionWorkerCapability(
            new ExecutionOccurrenceKey('task', 'occurrence'),
            'attempt',
            'run',
            'execution',
            1,
            'fence',
            'manifest',
            hash('sha256', PreparedConfiguration::canonical($policy)),
            'ledger',
            'runtime',
            'source-revision',
            $policy,
            launchDescriptor: new ExecutionLaunchDescriptor(
                'execution',
                1,
                'fixture-worker',
                occurrence: new ExecutionOccurrenceKey('task', 'occurrence'),
            ),
            permissionId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ), launchStartedAt: 0.0);
    }
}
