<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Console\Application;
use Yiisoft\Scheduler\Clock\SystemClock;
use Yiisoft\Scheduler\Handler\CallableTaskHandler;
use Yiisoft\Scheduler\Handler\QueueTaskHandler;
use Yiisoft\Scheduler\Handler\TaskHandlerInterface;
use Yiisoft\Scheduler\Schedule;
use Yiisoft\Scheduler\Scheduler;
use Yiisoft\Queue\QueueProducerInterface;
use Yiisoft\Scheduler\RecurringTask;

/** @var array $params */

return [
    ClockInterface::class => SystemClock::class,

    TaskHandlerInterface::class => static function (ContainerInterface $container): TaskHandlerInterface {
        // The application running the commands is registered under the yiisoft/yii-console class
        // name. Asking the container for the Symfony class it extends first would not find it:
        // where undefined classes are autowired, that builds a new application without any
        // command. The Symfony class is the fallback, for an application registered as one.
        $application = null;

        foreach (['Yiisoft\\Yii\\Console\\Application', Application::class] as $id) {
            if ($container->has($id)) {
                /** @var mixed $application */
                $application = $container->get($id);

                break;
            }
        }

        $handler = new CallableTaskHandler($application instanceof Application ? $application : null);

        if (interface_exists(QueueProducerInterface::class) && $container->has(QueueProducerInterface::class)) {
            /** @var QueueProducerInterface $producer */
            $producer = $container->get(QueueProducerInterface::class);

            return new QueueTaskHandler($producer, $handler);
        }

        return $handler;
    },

    Scheduler::class => static function (ContainerInterface $container) use ($params): Scheduler {
        $schedule = new Schedule();

        // "schedule:run" exits between runs and can only tell what already ran from a persisted
        // checkpoint, so the schedule is made stateful as soon as the application has a cache.
        if ($container->has(CacheInterface::class)) {
            /** @var CacheInterface $cache */
            $cache = $container->get(CacheInterface::class);
            $schedule->stateful($cache);
        }

        /** @var RecurringTask $task */
        foreach ($params['yiisoft/scheduler']['tasks'] as $task) {
            $schedule->task($task);
        }

        return new Scheduler(
            [$schedule],
            $container->get(TaskHandlerInterface::class),
            $container->get(ClockInterface::class),
            $container->has(EventDispatcherInterface::class) ? $container->get(EventDispatcherInterface::class) : null,
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
        );
    },
];
