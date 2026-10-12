<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A manually advanced clock for tests.
 */
final class TestClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $now = null)
    {
        $this->now = $now ?? new DateTimeImmutable('2026-01-01 00:00:00+00:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modify): void
    {
        $this->now = $this->now->modify($modify);
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
