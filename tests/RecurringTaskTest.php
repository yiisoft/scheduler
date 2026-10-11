<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests;

use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\RecurringTask;

final class RecurringTaskTest extends TestCase
{
    public function testUnparsableIntervalStringIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The interval string could not be parsed.');

        RecurringTask::every('garbage', 'app:noop');
    }

    public function testIntervalsOfDifferentLengthProduceDifferentIds(): void
    {
        $monthly = RecurringTask::every('1 month', 'app:monthly');
        $bimonthly = RecurringTask::every('2 months', 'app:monthly');
        $yearly = RecurringTask::every('1 year', 'app:monthly');

        $this->assertNotSame($monthly->getId(), $bimonthly->getId());
        $this->assertNotSame($monthly->getId(), $yearly->getId());
        $this->assertNotSame($bimonthly->getId(), $yearly->getId());
    }

    public function testMicrosecondIntervalDoesNotCollideWithAMonth(): void
    {
        $monthly = RecurringTask::every('1 month', 'app:task');
        $sub = RecurringTask::every('500 microseconds', 'app:task');

        $this->assertNotSame($monthly->getId(), $sub->getId());
    }
}
