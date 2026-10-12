<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\ExecutionFence;

final class ExecutionFenceTest extends TestCase
{
    public function testOnlyMatchingGenerationAndCapabilityIsEqual(): void
    {
        $fence = new ExecutionFence(2, 'generation-2');
        self::assertTrue($fence->equals(new ExecutionFence(2, 'generation-2')));
        self::assertFalse($fence->equals(new ExecutionFence(1, 'generation-2')));
        self::assertFalse($fence->equals(new ExecutionFence(2, 'generation-3')));
    }

    public function testInvalidAndUnboundedFencesAreRejected(): void
    {
        foreach ([fn() => new ExecutionFence(0, 'token'), fn() => new ExecutionFence(1, 'bad token'), fn() => new ExecutionFence(1, str_repeat('x', 129))] as $case) {
            try {
                $case();
                self::fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
