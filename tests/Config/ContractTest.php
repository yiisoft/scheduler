<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Schedule\Config\{PreparedConfiguration,SchedulerConfig,TaskDefinition,PhpConfigurationSource};
use Yiisoft\Schedule\Executor\{CallableCommand,ShellCommand};
use Yiisoft\Schedule\ExecutionMode;
use Yiisoft\Schedule\Task\TaskId;
use stdClass;

final class ContractTest extends TestCase
{
    public function testPreparedConfigurationRejectsDuplicateTasks(): void
    {
        $task = new TaskDefinition(new TaskId('task'), new ShellCommand('/bin/echo'));
        $this->expectException(InvalidArgumentException::class);
        new PreparedConfiguration([$task, $task]);
    }

    public function testDirectCommandAndTaskPoliciesAreExplicit(): void
    {
        $task = new TaskDefinition(new TaskId('task'), new CallableCommand(static function (): void {}), ExecutionMode::ASYNC);
        self::assertSame(ExecutionMode::ASYNC, $task->mode);
        self::assertSame('task', (string) $task->id);
    }

    public function testRemovedCompilationApiIsAbsent(): void
    {
        self::assertFalse(class_exists('Yiisoft\\Schedule\\Config\\Manifest'));
        self::assertFalse(method_exists(SchedulerConfig::class, 'compile'));
        self::assertFalse(method_exists(PhpConfigurationSource::class, 'compile'));
    }

    public function testUnsupportedNestedPayloadIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TaskDefinition(new TaskId('task'), new ShellCommand('/bin/echo'), metadata: ['bad' => new stdClass()]);
    }
}
