<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\{PreparedConfiguration,TaskDefinition,TimeoutPolicy,TriggerDefinition};
use Yiisoft\Scheduler\Executor\ShellCommand;
use Yiisoft\Scheduler\ExecutionMode;
use Yiisoft\Scheduler\Task\TaskId;

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

    public function testInvalidTriggerIsRejected(): void
    {
        $task = new TaskDefinition(new TaskId('task'), new ShellCommand('/bin/echo'), trigger: new TriggerDefinition('periodic', ['type' => 'periodic','seconds' => 0]));
        $this->expectException(InvalidArgumentException::class);
        $task->trigger->validate();
    }
}
