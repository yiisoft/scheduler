<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Handler;

use PHPUnit\Framework\TestCase;
use stdClass;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\Handler\ConsoleTaskHandler;
use Yiisoft\Scheduler\TaskContext;
use Yiisoft\Scheduler\Tests\Support\TestClock;
use Yiisoft\Scheduler\Trigger\CronExpressionTrigger;

use function file_get_contents;

final class CallableTaskHandlerTest extends TestCase
{
    public function testACallableIsInvokedWithTheContext(): void
    {
        $context = $this->context();
        $handler = new CallableTaskHandler();

        $this->assertSame($context, $handler->handle(static fn(TaskContext $given): TaskContext => $given, $context));
    }

    public function testTheHandlerNamesNothingOutsideThePackage(): void
    {
        // The whole point of this handler: a schedule of callables runs with neither a console
        // application nor a queue producer installed, so neither package may be named here.
        $source = (string) file_get_contents(__DIR__ . '/../../src/Handler/CallableTaskHandler.php');

        $this->assertStringNotContainsString('Symfony', $source);
        $this->assertStringNotContainsString('Yiisoft\\Queue', $source);
    }

    public function testAStringTaskPointsAtTheConsoleHandler(): void
    {
        // A string task is the name of a console command, and this handler cannot run one. It
        // is checked before the callable branch: "strlen" is a callable string.
        $handler = new CallableTaskHandler();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(ConsoleTaskHandler::class);

        $handler->handle('strlen', $this->context());
    }

    public function testAnObjectTaskPointsAtAQueueProducer(): void
    {
        $handler = new CallableTaskHandler();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Configure a queue producer to push object tasks');

        $handler->handle(new stdClass(), $this->context());
    }

    private function context(): TaskContext
    {
        return new TaskContext('default', 'id', new CronExpressionTrigger('* * * * *'), (new TestClock())->now(), null);
    }
}
