<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

/**
 * Provides a schedule, e.g. built from configuration or loaded from a database.
 */
interface ScheduleProviderInterface
{
    public function getSchedule(): Schedule;
}
