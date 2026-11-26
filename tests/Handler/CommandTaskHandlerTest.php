<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Handler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Executor\CallableCommand;
use Yiisoft\Scheduler\Executor\ExecutorMap;
use Yiisoft\Scheduler\Executor\PhpCommand;
use Yiisoft\Scheduler\Executor\ProcessCommand;
use Yiisoft\Scheduler\Executor\ProcessExecutor;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\Handler\CommandTaskHandler;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\TaskContext;
use Yiisoft\Scheduler\Tests\Support\InMemoryCache;
use Yiisoft\Scheduler\Tests\Support\TestClock;
use Yiisoft\Scheduler\Trigger\CronExpressionTrigger;

/**
 * The handler in front of the chain: a command goes to its executor, and anything else carries
 * on to the handlers that read the older task values.
 */
final class CommandTaskHandlerTest extends TestCase
{
    public function testACommandIsRunByTheExecutorRegisteredForIt(): void
    {
        $handler = new CommandTaskHandler(ExecutorMap::standard());
        $ran = 0;

        $command = CallableCommand::call(static function () use (&$ran): string {
            ++$ran;

            return 'done';
        });

        $this->assertSame('done', $handler->handle($command, $this->context()));
        $this->assertSame(1, $ran);
    }

    public function testATaskThatIsNotACommandCarriesOnDownTheChain(): void
    {
        $handler = new CommandTaskHandler(ExecutorMap::standard(), new CallableTaskHandler());
        $ran = 0;

        $handler->handle(static function () use (&$ran): void {
            ++$ran;
        }, $this->context());

        $this->assertSame(1, $ran);
    }

    public function testACommandWithNoExecutorNeverReachesTheChainBehindIt(): void
    {
        $handler = new CommandTaskHandler(new ExecutorMap([ProcessCommand::class => new ProcessExecutor()]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No executor is registered for "' . PhpCommand::class . '"');

        $handler->handle(new PhpCommand('report.php'), $this->context());
    }

    public function testASchedulerRunsACommandTaskThroughTheHandler(): void
    {
        $clock = new TestClock();
        $ran = 0;

        $task = RecurringTask::cron('* * * * *', CallableCommand::call(static function () use (&$ran): void {
            ++$ran;
        }));

        $scheduler = new Scheduler(
            [(new Schedule())->stateful(new InMemoryCache())->task($task)],
            new CommandTaskHandler(ExecutorMap::standard()),
            $clock,
        );

        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertSame(1, $ran);
    }

    public function testTheFluentBuilderDeclaresACommandTask(): void
    {
        $command = new ProcessCommand('/usr/bin/report', ['--weekly']);
        $task = RecurringTask::everyMinute()->run($command);

        $this->assertSame($task->getId(), RecurringTask::everyMinute()->run($command)->getId());
        $this->assertNotSame(
            $task->getId(),
            RecurringTask::everyMinute()->run(new ProcessCommand('/usr/bin/report'))->getId(),
        );
    }

    private function context(): TaskContext
    {
        return new TaskContext('default', 'task', new CronExpressionTrigger('* * * * *'), new DateTimeImmutable(), null);
    }
}
