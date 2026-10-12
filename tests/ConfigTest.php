<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests;

use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Queue\Message\GenericMessage;
use Yiisoft\Queue\Message\MessageInterface;
use Yiisoft\Queue\MessageStatus;
use Yiisoft\Queue\QueueProducerInterface;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\Handler\QueueTaskHandler;
use Yiisoft\Scheduler\Handler\TaskHandlerInterface;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Scheduler\TaskContext;
use Yiisoft\Scheduler\Trigger\CronExpressionTrigger;
use Yiisoft\Scheduler\Tests\Support\InMemoryCache;
use Yiisoft\Scheduler\Tests\Support\StubContainer;
use Yiisoft\Scheduler\Tests\Support\TestClock;

use function dirname;

final class ConfigTest extends TestCase
{
    public function testCronStyleInvocationsRunTasksEveryMinute(): void
    {
        $cache = new InMemoryCache();
        $clock = new TestClock();
        $ran = 0;

        $container = new StubContainer([
            ClockInterface::class => $clock,
            TaskHandlerInterface::class => new CallableTaskHandler(),
            CacheInterface::class => $cache,
        ]);

        $factory = $this->schedulerFactory([
            RecurringTask::cron('* * * * *', static function () use (&$ran): void {
                ++$ran;
            }),
        ]);

        // Every invocation is a separate process, as a "* * * * * php yii schedule:run" entry is.
        $factory($container)->tick();

        for ($minute = 0; $minute < 10; ++$minute) {
            $clock->advance('+1 minute');
            $factory($container)->tick();
        }

        $this->assertSame(10, $ran);
    }

    public function testScheduleIsStatelessWithoutACacheInTheContainer(): void
    {
        $container = new StubContainer([
            ClockInterface::class => new TestClock(),
            TaskHandlerInterface::class => new CallableTaskHandler(),
        ]);

        $scheduler = $this->schedulerFactory([])($container);

        $this->assertFalse($scheduler->getSchedules()['default']->isStateful());
    }

    public function testConsoleCommandTaskRunsInTheApplicationOfTheContainer(): void
    {
        $command = new class ('report:daily') extends Command {
            public int $ran = 0;

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                ++$this->ran;

                return Command::SUCCESS;
            }
        };

        $application = new Application();
        $application->setAutoExit(false);
        $application->setCommandLoader(new FactoryCommandLoader(['report:daily' => static fn() => $command]));

        $container = new StubContainer([
            'Yiisoft\\Yii\\Console\\Application' => $application,
        ]);

        $handler = $this->handlerFactory()($container);
        $handler->handle('report:daily', $this->context());

        $this->assertSame(1, $command->ran);
    }

    public function testConsoleCommandTaskRunsInASymfonyApplicationOfTheContainer(): void
    {
        $command = new class ('report:daily') extends Command {
            public int $ran = 0;

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                ++$this->ran;

                return Command::SUCCESS;
            }
        };

        $application = new Application();
        $application->setAutoExit(false);
        $application->setCommandLoader(new FactoryCommandLoader(['report:daily' => static fn() => $command]));

        $handler = $this->handlerFactory()(new StubContainer([Application::class => $application]));
        $handler->handle('report:daily', $this->context());

        $this->assertSame(1, $command->ran);
    }

    public function testHandlerHasNoApplicationWithoutOneInTheContainer(): void
    {
        $handler = $this->handlerFactory()(new StubContainer());

        $this->assertInstanceOf(CallableTaskHandler::class, $handler);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the task handler has no console application');

        $handler->handle('report:daily', $this->context());
    }

    public function testMessageTaskIsPushedAndCommandTaskStillRunsWithAQueueProducer(): void
    {
        $command = new class ('report:daily') extends Command {
            public int $ran = 0;

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                ++$this->ran;

                return Command::SUCCESS;
            }
        };

        $application = new Application();
        $application->setAutoExit(false);
        $application->setCommandLoader(new FactoryCommandLoader(['report:daily' => static fn() => $command]));

        $producer = new class implements QueueProducerInterface {
            /** @var list<MessageInterface> */
            public array $pushed = [];

            public function push(MessageInterface $message): MessageInterface
            {
                $this->pushed[] = $message;

                return $message;
            }

            public function status(string|int $id): MessageStatus
            {
                return MessageStatus::WAITING;
            }

            public function getQueueName(): string
            {
                return 'default';
            }
        };

        $container = new StubContainer([
            'Yiisoft\\Yii\\Console\\Application' => $application,
            QueueProducerInterface::class => $producer,
        ]);

        $handler = $this->handlerFactory()($container);

        $this->assertInstanceOf(QueueTaskHandler::class, $handler);

        $handler->handle('report:daily', $this->context());
        $handler->handle(GenericMessage::fromPayload('metrics.collect', []), $this->context());

        $this->assertSame(1, $command->ran);
        $this->assertCount(1, $producer->pushed);
    }

    private function context(): TaskContext
    {
        return new TaskContext('default', 'task', new CronExpressionTrigger('* * * * *'), new DateTimeImmutable(), null);
    }

    /**
     * @return Closure(ContainerInterface): TaskHandlerInterface
     */
    private function handlerFactory(): Closure
    {
        /** @var Closure(ContainerInterface): TaskHandlerInterface */
        return $this->loadDefinitions(require dirname(__DIR__) . '/config/params.php')[TaskHandlerInterface::class];
    }

    /**
     * @param list<RecurringTask> $tasks
     *
     * @return Closure(ContainerInterface): Scheduler
     */
    private function schedulerFactory(array $tasks): Closure
    {
        $params = require dirname(__DIR__) . '/config/params.php';
        $params['yiisoft/scheduler']['tasks'] = $tasks;

        $definitions = $this->loadDefinitions($params);

        /** @var Closure(ContainerInterface): Scheduler */
        return $definitions[Scheduler::class];
    }

    /**
     * @param array $params Read by the configuration file from the scope including it.
     *
     * @return array<string, mixed>
     */
    private function loadDefinitions(array $params): array
    {
        return require dirname(__DIR__) . '/config/di.php';
    }
}
