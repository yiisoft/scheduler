<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Handler;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Scheduler\Event\FailureEvent;
use Yiisoft\Scheduler\Exception\RuntimeException;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\TaskContext;
use Yiisoft\Scheduler\Tests\Support\TestClock;
use Yiisoft\Scheduler\Trigger\CronExpressionTrigger;
use Psr\EventDispatcher\EventDispatcherInterface;

final class CallableTaskHandlerTest extends TestCase
{
    public function testSuccessfulCommandReturnsItsExitCode(): void
    {
        $handler = new CallableTaskHandler($this->application(Command::SUCCESS));

        $this->assertSame(0, $handler->handle('app:task', $this->context()));
    }

    public function testCommandExitingWithANonZeroCodeIsAFailure(): void
    {
        $handler = new CallableTaskHandler($this->application(Command::FAILURE));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The "app:task --force" console command exited with code 1.');

        $handler->handle('app:task --force', $this->context());
    }

    public function testFailedCommandIsReportedAsATaskFailure(): void
    {
        $clock = new TestClock();

        $dispatcher = new class implements EventDispatcherInterface {
            /** @var list<string> */
            public array $events = [];

            public function dispatch(object $event): object
            {
                $this->events[] = $event::class;

                if ($event instanceof FailureEvent) {
                    $event->ignore();
                }

                return $event;
            }
        };

        $scheduler = new Scheduler(
            [(new Schedule())->task(RecurringTask::cron('* * * * *', 'app:task'))],
            new CallableTaskHandler($this->application(Command::FAILURE)),
            $clock,
            $dispatcher,
        );

        $scheduler->tick();
        $clock->advance('+1 minute');
        $scheduler->tick();

        $this->assertContains(FailureEvent::class, $dispatcher->events);
    }

    private function application(int $exitCode): Application
    {
        $command = new class ($exitCode) extends Command {
            public function __construct(private readonly int $exitCode)
            {
                parent::__construct('app:task');
            }

            protected function configure(): void
            {
                $this->addOption('force');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return $this->exitCode;
            }
        };

        $application = new Application();
        $application->setAutoExit(false);
        $application->setCommandLoader(new FactoryCommandLoader(['app:task' => static fn() => $command]));

        return $application;
    }

    private function context(): TaskContext
    {
        return new TaskContext('default', 'id', new CronExpressionTrigger('* * * * *'), (new TestClock())->now(), null);
    }
}
