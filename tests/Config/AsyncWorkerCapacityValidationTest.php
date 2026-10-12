<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\PreparedConfiguration;

final class AsyncWorkerCapacityValidationTest extends TestCase
{
    public function testPositiveIntegerCapacityIsSupported(): void
    {
        self::assertSame(3, (new PreparedConfiguration(asyncWorkerCapacity: 3))->asyncWorkerCapacity);
    }

    public function testNonPositiveCapacityIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PreparedConfiguration(asyncWorkerCapacity: 0);
    }
}
