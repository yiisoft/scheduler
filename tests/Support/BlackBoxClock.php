<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class BlackBoxClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('@2000');
    }
}
