<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\{PreparedConfiguration,TaskDefinition};
use Yiisoft\Scheduler\Executor\ShellCommand;
use Yiisoft\Scheduler\Task\TaskId;
use stdClass;

final class AggregateValidationTest extends TestCase
{
    public function testIdentifiersRejectTrailingNewlines(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TaskId("task\n");
    }

    public function testPreparedConfigurationAppliesAggregateBudget(): void
    {
        $tasks = [];
        for ($i = 0;$i < 16;$i++) {
            $tasks[] = new TaskDefinition(new TaskId('task-' . $i), new ShellCommand('/bin/echo'), metadata: ['large' => str_repeat('x', 65536)]);
        } $this->expectException(InvalidArgumentException::class);
        new PreparedConfiguration($tasks);
    }

    public function testPreparedConfigurationRejectsMalformedDescriptors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PreparedConfiguration([new stdClass()]);
    }
}
