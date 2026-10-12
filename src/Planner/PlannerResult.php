<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

final class PlannerResult
{
    public const PROGRESSED = 'progressed';
    public const WAITING = 'waiting';
    public const STOPPED = 'stopped';
    public const VERSION_MISMATCH = 'version-mismatch';

    private function __construct(public readonly string $status, public readonly int $admitted = 0) {}

    public static function progressed(int $admitted = 0): self
    {
        return new self(self::PROGRESSED, $admitted);
    }

    public static function waiting(): self
    {
        return new self(self::WAITING);
    }

    public static function stopped(): self
    {
        return new self(self::STOPPED);
    }

    public static function versionMismatch(): self
    {
        return new self(self::VERSION_MISMATCH);
    }
}
