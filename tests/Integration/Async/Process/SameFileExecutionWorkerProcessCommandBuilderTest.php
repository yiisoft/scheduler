<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Integration\Async\Process;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Schedule\Config\PreparedConfiguration;
use Yiisoft\Schedule\Integration\Async\WorkerLifecyclePolicy;
use Yiisoft\Schedule\Executor\ShellVariableOverrides;
use Yiisoft\Schedule\Integration\Async\ExecutionOccurrenceKey;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerCapability;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerLaunchRequest;
use Yiisoft\Schedule\Integration\Async\MonotonicClockInterface;
use Yiisoft\Schedule\Integration\Async\Process\SameFileExecutionWorkerProcessCommandBuilder;

use function putenv;

final class SameFileExecutionWorkerProcessCommandBuilderTest extends TestCase
{
    public function testRejectsLegacyCapabilityVersionMarker(): void
    {
        $encoded = $this->request()->capability->encode();
        $this->expectException(InvalidArgumentException::class);
        ExecutionWorkerCapability::decode(str_replace('"version":3', '"version":2', $encoded));
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
        self::assertSame(['APP_MODE' => 'application', 'APP_SECRET' => 'in-memory'], $process->environment);
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
        $policy = ['format' => 3, 'source' => [null, null], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => null, 'metadata' => [], 'schedule' => 'schedule', 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]];
        return new ExecutionWorkerLaunchRequest(new ExecutionWorkerCapability(
            new ExecutionOccurrenceKey('schedule', 'task', 'occurrence'),
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
        ), launchStartedAt: 0.0);
    }
}
