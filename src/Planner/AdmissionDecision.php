<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

final class AdmissionDecision
{
    public const ADMIT = 'admit';
    public const DEFER_CAPACITY = 'defer-capacity';
    public const SKIP_OVERLAP = 'skip-overlap';

    private function __construct(public readonly string $kind) {}

    public static function admit(): self
    {
        return new self(self::ADMIT);
    }

    public static function deferCapacity(): self
    {
        return new self(self::DEFER_CAPACITY);
    }

    public static function skipOverlap(): self
    {
        return new self(self::SKIP_OVERLAP);
    }
}
