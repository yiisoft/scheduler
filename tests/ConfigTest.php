<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\Handler\TaskHandlerInterface;
use Yiisoft\Scheduler\RecurringTask;
use Yiisoft\Scheduler\Scheduler;
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
