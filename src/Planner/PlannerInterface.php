<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

interface PlannerInterface
{
    public function tick(): PlannerResult;

    public function run(): PlannerResult;

    public function stop(): void;
}
