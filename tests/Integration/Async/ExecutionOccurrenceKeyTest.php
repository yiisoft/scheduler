<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\ExecutionOccurrenceKey;

final class ExecutionOccurrenceKeyTest extends TestCase
{
    public function testEncodingIsStableAndOccurrencesDoNotCollide(): void
    {
        $first = new ExecutionOccurrenceKey('schedule', 'task', 'occurrence-1');
        $second = new ExecutionOccurrenceKey('schedule', 'task', 'occurrence-2');
        self::assertSame('schedule:task:occurrence-1', (string) $first);
        self::assertFalse($first->equals($second));
        self::assertTrue($first->equals(new ExecutionOccurrenceKey('schedule', 'task', 'occurrence-1')));
    }

    public function testPartsAreBoundedPortableIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExecutionOccurrenceKey('bad/key', 'task', 'occurrence');
    }
}
