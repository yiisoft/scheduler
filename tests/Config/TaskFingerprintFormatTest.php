<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Yiisoft\Scheduler\Config\{PreparedConfiguration,TaskDefinition};
use Yiisoft\Scheduler\Executor\ShellCommand;
use Yiisoft\Scheduler\Task\TaskId;

use const INF;
use const NAN;

final class TaskFingerprintFormatTest extends TestCase
{
    public function testFingerprintUsesCurrentSchemaProjection(): void
    {
        $task = new TaskDefinition(new TaskId('task'), new ShellCommand('/bin/echo'), metadata: ['key' => 'value']);
        $prepared = (new PreparedConfiguration([$task]))->withSource('source', 'revision');
        $projection = $prepared->taskPolicyProjection($task);
        self::assertSame('dev', $projection['schema']);
        self::assertSame($prepared->taskFingerprint($task), hash('sha256', PreparedConfiguration::canonical($projection)));
        self::assertNotSame($prepared->fingerprint(), $prepared->taskFingerprint($task));
    }

    public function testNumericPolicySchemaIsRejected(): void
    {
        $task = new TaskDefinition(new TaskId('task'), new ShellCommand('/bin/echo'));
        $policy = (new PreparedConfiguration([$task]))->withSource('source', 'revision')->taskPolicyProjection($task);
        $policy['schema'] = 2;
        $this->expectException(InvalidArgumentException::class);
        PreparedConfiguration::assertTaskPolicyProjection($policy);
    }

    public function testNullTimeoutAndPathSourceAreAccepted(): void
    {
        $policy = $this->policy();
        $policy['source'] = ['/var/lib/scheduler/source','revision-1'];
        PreparedConfiguration::assertTaskPolicyProjection($policy);
        self::assertTrue(true);
    }

    public function testLastMissedOccurrencePolicyWithNullMaxIsAccepted(): void
    {
        $policy = $this->policy();
        $policy['task']['missed'] = ['last',null];
        PreparedConfiguration::assertTaskPolicyProjection($policy);
        self::assertTrue(true);
    }

    public function testSynchronousPolicyMayOmitRecoveryMaxAge(): void
    {
        $policy = $this->policy();
        $policy['task']['mode'] = 'sync';
        $policy['task']['recoveryMaxAge'] = null;
        PreparedConfiguration::assertTaskPolicyProjection($policy);
        self::assertTrue(true);
    }

    public function testSynchronousPolicyRejectsRecoveryMaxAge(): void
    {
        $policy = $this->policy();
        $policy['task']['mode'] = 'sync';
        $this->expectException(InvalidArgumentException::class);
        PreparedConfiguration::assertTaskPolicyProjection($policy);
    }

    /** @dataProvider invalidRecoveryMaxAgeProvider */
    public function testAsyncPolicyRequiresPositiveFiniteRecoveryMaxAge(mixed $value): void
    {
        $policy = $this->policy();
        $policy['task']['recoveryMaxAge'] = $value;
        $this->expectException(InvalidArgumentException::class);
        PreparedConfiguration::assertTaskPolicyProjection($policy);
    }

    public static function invalidRecoveryMaxAgeProvider(): array
    {
        return [[null], [0.0], [-1.0], [INF], [NAN], [180]];
    }

    public function testEmptySourceIsRejected(): void
    {
        $policy = $this->policy();
        $policy['source'] = ['','revision'];
        $this->expectException(InvalidArgumentException::class);
        PreparedConfiguration::assertTaskPolicyProjection($policy);
    }

    /** @dataProvider invalidPolicyProvider */
    public function testMalformedPolicyIsRejected(array $change): void
    {
        $policy = $this->policy();
        $policy['task'] = array_replace($policy['task'], $change);
        $this->expectException(InvalidArgumentException::class);
        PreparedConfiguration::assertTaskPolicyProjection($policy);
    }

    public static function invalidPolicyProvider(): array
    {
        return [
            'empty timeout' => [['timeout' => [null, null]]], 'empty source' => [['id' => '']],
            'invalid task id' => [['id' => 'not valid']], 'old schedule field' => [['schedule' => 'schedule']], 'invalid schedule' => [['schedule' => '']],
            'invalid overlap' => [['overlap' => 'bad']], 'invalid missed' => [['missed' => ['all', null]]],
            'invalid trigger reference' => [['trigger' => ['bad reference', []]]],
            'invalid metadata' => [['metadata' => [new stdClass()]]],
        ];
    }

    private function policy(): array
    {
        return ['schema' => 'dev','source' => ['source','revision'],'task' => ['id' => 'task','mode' => 'async','timeout' => null,'recoveryMaxAge' => 180.0,'metadata' => [],'trigger' => ['periodic',['type' => 'periodic','seconds' => 60,'from' => null,'until' => null]],'overlap' => 'allow','missed' => ['skip',null]]];
    }
}
