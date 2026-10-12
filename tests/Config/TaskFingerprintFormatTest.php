<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Schedule\Config\{PreparedConfiguration,TaskDefinition};
use Yiisoft\Schedule\Executor\ShellCommand;
use Yiisoft\Schedule\Task\TaskId;
use stdClass;

final class TaskFingerprintFormatTest extends TestCase
{
    public function testFingerprintUsesApprovedFormatThreeProjection(): void
    {
        $task = new TaskDefinition(new TaskId('task'), new ShellCommand('/bin/echo'), metadata: ['key' => 'value']);
        $prepared = (new PreparedConfiguration([$task]))->withSource('source', 'revision');
        $projection = $prepared->taskPolicyProjection($task);
        self::assertSame(3, $projection['format']);
        self::assertSame($prepared->taskFingerprint($task), hash('sha256', PreparedConfiguration::canonical($projection)));
        self::assertNotSame($prepared->fingerprint(), $prepared->taskFingerprint($task));
    }

    public function testFormatTwoPolicyIsRejected(): void
    {
        $task = new TaskDefinition(new TaskId('task'), new ShellCommand('/bin/echo'));
        $policy = (new PreparedConfiguration([$task]))->withSource('source', 'revision')->taskPolicyProjection($task);
        $policy['format'] = 2;
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
            'invalid task id' => [['id' => 'not valid']], 'invalid schedule' => [['schedule' => '']],
            'invalid overlap' => [['overlap' => 'bad']], 'invalid missed' => [['missed' => ['all', null]]],
            'invalid trigger reference' => [['trigger' => ['bad reference', []]]],
            'invalid metadata' => [['metadata' => [new stdClass()]]],
        ];
    }

    private function policy(): array
    {
        return ['format' => 3,'source' => ['source','revision'],'task' => ['id' => 'task','mode' => 'async','timeout' => null,'metadata' => [],'schedule' => 'schedule','trigger' => ['periodic',['type' => 'periodic','seconds' => 60,'from' => null,'until' => null]],'overlap' => 'allow','missed' => ['skip',null]]];
    }
}
