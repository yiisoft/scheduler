<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\{PreparedConfiguration,SchedulerConfig,TaskDefinition,TimeoutPolicy,TriggerDefinition};
use Yiisoft\Scheduler\Executor\ShellCommand;
use Yiisoft\Scheduler\ExecutionMode;
use Yiisoft\Scheduler\Task\TaskId;

use const INF;
use const NAN;

final class ValidationTest extends TestCase
{
    public function testEffectiveTimeoutMergesGlobalAndTaskPolicies(): void
    {
        $task = new TaskDefinition(new TaskId('task'), new ShellCommand('/bin/echo'), timeout: new TimeoutPolicy(null, 5));
        $prepared = new PreparedConfiguration([$task], new TimeoutPolicy(2, 8));
        $effective = $prepared->timeoutFor($task);
        self::assertSame(2.0, $effective->softSeconds);
        self::assertSame(5.0, $effective->hardSeconds);
    }

    public function testSynchronousTaskRejectsTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PreparedConfiguration([new TaskDefinition(new TaskId('task'), new ShellCommand('/bin/echo'), ExecutionMode::SYNC, new TimeoutPolicy(1))]);
    }

    public function testRecoveryMaxAgeDefaultsAndTaskOverrideIsProjected(): void
    {
        $task = new TaskDefinition(new TaskId('task'), new ShellCommand('/bin/echo'), recoveryMaxAge: 12.5);
        $prepared = new PreparedConfiguration([$task]);
        self::assertSame(12.5, $prepared->recoveryMaxAgeFor($task));
        self::assertSame(12.5, $prepared->taskPolicyProjection($task)['task']['recoveryMaxAge']);
        $defaultTask = new TaskDefinition(new TaskId('other'), new ShellCommand('/bin/echo'));
        $default = new PreparedConfiguration([$defaultTask]);
        self::assertSame(180.0, $default->taskPolicyProjection($defaultTask)['task']['recoveryMaxAge']);
        $changed = new PreparedConfiguration([new TaskDefinition(new TaskId('other'), new ShellCommand('/bin/echo'))], recoveryMaxAge: 12.5);
        self::assertNotSame($default->fingerprint(), $changed->fingerprint());
    }

    /** @dataProvider invalidRecoveryMaxAgeProvider */
    public function testRecoveryMaxAgeMustBePositiveAndFinite(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SchedulerConfig())->recoveryMaxAge($value);
    }

    public static function invalidRecoveryMaxAgeProvider(): array
    {
        return [[0.0], [-1.0], [INF], [-INF], [NAN]];
    }

    public function testInvalidTriggerIsRejected(): void
    {
        $task = new TaskDefinition(new TaskId('task'), new ShellCommand('/bin/echo'), trigger: new TriggerDefinition('periodic', ['type' => 'periodic','seconds' => 0]));
        $this->expectException(InvalidArgumentException::class);
        $task->trigger->validate();
    }
}
