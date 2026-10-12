<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Callable;

use PHPUnit\Framework\TestCase;
use Yiisoft\Schedule\Callable\InvocationGuardContainer;
use Yiisoft\Schedule\Tests\Support\StubContainer;
use Throwable;

final class InvocationGuardContainerTest extends TestCase
{
    public function testGuardsBeforeAndAfterHasAndGet(): void
    {
        $calls = 0;
        $container = new InvocationGuardContainer(new StubContainer(['value' => 42]), static function () use (&$calls): void {
            ++$calls;
        });

        self::assertTrue($container->has('value'));
        self::assertSame(42, $container->get('value'));
        self::assertSame(4, $calls);
    }

    public function testAfterGuardRunsWhenAccessThrows(): void
    {
        $calls = 0;
        $container = new InvocationGuardContainer(new StubContainer(), static function () use (&$calls): void {
            ++$calls;
        });
        try {
            $container->get('missing');
        } catch (Throwable) {
        }
        self::assertSame(2, $calls);
    }
}
