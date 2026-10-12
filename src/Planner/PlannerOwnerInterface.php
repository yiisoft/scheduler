<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Planner;

interface PlannerOwnerInterface
{
    public function sourceId(): string;

    public function ownerToken(): string;

    public function stateDirectory(): string;

    public function release(): void;
}
