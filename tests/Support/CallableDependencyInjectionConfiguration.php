<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Yiisoft\Scheduler\Config\SchedulerConfig;
use Yiisoft\Scheduler\Executor\CallableCommand;
use Yiisoft\Scheduler\Tests\Support\CallableDependencyInjectionService;

/**
 * The source is deliberately a zero-argument factory.  The container belongs
 * to the configuration/runtime graph, rather than to the planner process.
 */
return static function (): SchedulerConfig {
    $config = new SchedulerConfig(static function (): ContainerInterface {
        return new CallableDependencyInjectionService((string) getenv('SCHEDULER_ACCEPTANCE_DIR'));
    });
    $config->every(new CallableCommand(
        static function (CallableDependencyInjectionService $service, string $effect, int $value): void {
            $service->record($effect, $value);
        },
        ['effect' => 'async.effect', 'value' => 7],
    ), 1)->id('callable-async')->async();
    $config->every(new CallableCommand(
        static function (CallableDependencyInjectionService $service, string $effect, int $value): void {
            $service->record($effect, $value);
        },
        ['effect' => 'sync.effect', 'value' => 11],
    ), 1)->id('callable-sync')->sync();

    return $config;
};
