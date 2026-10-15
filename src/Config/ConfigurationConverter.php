<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Config;

use InvalidArgumentException;
use Yiisoft\Scheduler\Executor\{CommandExecutorMap, CommandExecutorRuntimeFactory};

/** Converts source declarations while keeping command handles strictly process-local. */
final class ConfigurationConverter
{
    public function convert(SchedulerConfig $configuration, CommandExecutorRuntimeFactory $runtimeFactory): PreparedConfiguration
    {
        // The runtime owns the process-local built-ins (including the exact
        // ShellCommand and CallableCommand bindings).  Start with that map;
        // configuration is only an overlay and never a registry.
        $runtime = $runtimeFactory->create($configuration->containerProvider());
        $map = new CommandExecutorMap($runtime->commandExecutorMap()->all());
        $callback = $configuration->commandExecutorMapCallback();
        if ($callback !== null) {
            $overlay = $callback($runtime);
            if (!$overlay instanceof CommandExecutorMap) {
                throw new InvalidArgumentException('Command executor map callback must return CommandExecutorMap.');
            }
            $map = $map->with($overlay->all());
        }

        $tasks = [];
        foreach ($configuration->schedules() as $schedule) {
            $schedule->trigger->validate();
            $tasks[] = new TaskDefinition(
                $schedule->id,
                $schedule->command,
                $schedule->mode,
                $schedule->timeout,
                $schedule->metadata,
                $schedule->trigger,
                $schedule->missedOccurrences,
                $schedule->overlap,
            );
        }
        return new PreparedConfiguration($tasks, $configuration->timeoutPolicy(), null, null, $configuration->asyncWorkerCapacityValue(), $map, $configuration->containerProvider());
    }
}
