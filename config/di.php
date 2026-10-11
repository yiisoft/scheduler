<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
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
        if (interface_exists(QueueProducerInterface::class) && $container->has(QueueProducerInterface::class)) {
            return $container->get(QueueTaskHandler::class);
        }

        return $container->get(CallableTaskHandler::class);
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
