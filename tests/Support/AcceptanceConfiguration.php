<?php

declare(strict_types=1);

use Yiisoft\Scheduler\Config\SchedulerConfig;
use Yiisoft\Scheduler\Executor\CallableCommand;
use Yiisoft\Scheduler\Executor\ShellCommand;

if (!\function_exists('yii_schedule_acceptance_sync_effect')) {
    function yii_schedule_acceptance_sync_effect(): void
    {
        file_put_contents((string) getenv('SCHEDULER_ACCEPTANCE_SYNC'), (string) getmypid() . "\n", \FILE_APPEND | \LOCK_EX);
    }
}

$directory = (string) getenv('SCHEDULER_ACCEPTANCE_DIR');
// A duplicate planner must be rejected before loading/evaluating its source.
// This sentinel is set only by that probe and is intentionally never a task
// effect from the already-running planner.
if (($sentinel = getenv('SCHEDULER_ACCEPTANCE_DUPLICATE_SENTINEL')) !== false) {
    file_put_contents($sentinel, "evaluated\n", \LOCK_EX);
}

return static function () use ($directory): SchedulerConfig {
    $config = new SchedulerConfig();
    $config->every(new ShellCommand(
        \PHP_BINARY,
        [(string) getenv('SCHEDULER_ACCEPTANCE_EXTERNAL_COMMAND'), $directory, 'external-effect'],
    ), 1)->id('external-task');
    // The fixed anchor makes the cron occurrence immediately eligible without a
    // wall-clock sleep or a boundary race.
    $config->cron(new ShellCommand(
        \PHP_BINARY,
        [(string) getenv('SCHEDULER_ACCEPTANCE_EXTERNAL_COMMAND'), $directory, 'cron-effect'],
    ), '0 0 1 1 *', 'UTC', new DateTimeImmutable('2000-01-01T00:00:00+00:00'), new DateTimeImmutable('2000-01-01T00:00:00+00:00'))->id('cron-task');
    $config->every(new CallableCommand('yii_schedule_acceptance_sync_effect'), 1)
        ->sync()->id('sync-task');

    return $config;
};
