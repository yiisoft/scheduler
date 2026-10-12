<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Callable;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Yiisoft\Scheduler\Callable\ContainerProvider;
use Yiisoft\Scheduler\Callable\EmptyContainer;
use Yiisoft\Scheduler\Tests\Support\StubContainer;

final class ContainerProviderTest extends TestCase
{
    public function testContainerObjectWinsOverInvokableInterpretation(): void
    {
        $container = new class implements ContainerInterface {
            public int $invocations = 0;

            public function __invoke(): ContainerInterface
            {
                ++$this->invocations;
                return new StubContainer();
            }

            public function get(string $id): mixed
            {
                return 'value';
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        self::assertSame($container, (new ContainerProvider($container))->resolve());
        self::assertSame(0, $container->invocations);
    }

    public function testFactoryIsLazyAndCalledOnce(): void
    {
        $calls = 0;
        $container = new StubContainer(['answer' => 42]);
        $provider = new ContainerProvider(function () use (&$calls, $container): ContainerInterface {
            ++$calls;
            return $container;
        });

        self::assertSame(0, $calls);
        self::assertSame($container, $provider->resolve());
        self::assertSame($container, $provider->resolve());
        self::assertSame(1, $calls);
    }

    public function testProvidersAreIndependent(): void
    {
        $firstCalls = 0;
        $secondCalls = 0;
        $first = new StubContainer(['id' => 'first']);
        $second = new StubContainer(['id' => 'second']);
        $firstProvider = new ContainerProvider(function () use (&$firstCalls, $first): ContainerInterface {
            ++$firstCalls;
            return $first;
        });
        $secondProvider = new ContainerProvider(function () use (&$secondCalls, $second): ContainerInterface {
            ++$secondCalls;
            return $second;
        });

        self::assertSame($first, $firstProvider->resolve());
        self::assertSame($second, $secondProvider->resolve());
        self::assertSame(1, $firstCalls);
        self::assertSame(1, $secondCalls);
    }

    public function testInvalidFactoryReturnIsCached(): void
    {
        $calls = 0;
        $provider = new ContainerProvider(function () use (&$calls): mixed {
            ++$calls;
            return 'not a container';
        });

        try {
            $provider->resolve();
            self::fail('Expected invalid factory result.');
        } catch (InvalidArgumentException $first) {
            try {
                $provider->resolve();
                self::fail('Expected cached invalid factory result.');
            } catch (InvalidArgumentException $second) {
                self::assertSame($first, $second);
            }
        }
        self::assertSame(1, $calls);
    }

    public function testFactoryExceptionIsCached(): void
    {
        $calls = 0;
        $exception = new RuntimeException('bootstrap failed');
        $provider = new ContainerProvider(function () use (&$calls, $exception): never {
            ++$calls;
            throw $exception;
        });

        try {
            $provider->resolve();
            self::fail('Expected factory exception.');
        } catch (RuntimeException $first) {
            try {
                $provider->resolve();
                self::fail('Expected cached factory exception.');
            } catch (RuntimeException $second) {
                self::assertSame($first, $second);
            }
        }
        self::assertSame(1, $calls);
    }

    public function testRecursiveResolutionFailsAndIsCached(): void
    {
        $calls = 0;
        $provider = null;
        $provider = new ContainerProvider(function () use (&$calls, &$provider): ContainerInterface {
            ++$calls;
            return $provider->resolve();
        });

        try {
            $provider->resolve();
            self::fail('Expected recursive resolution to fail.');
        } catch (LogicException $first) {
            try {
                $provider->resolve();
                self::fail('Expected cached recursive failure.');
            } catch (LogicException $second) {
                self::assertSame($first, $second);
            }
        }
        self::assertSame(1, $calls);
    }

    public function testEmptyResolverHasPsr11Semantics(): void
    {
        $container = (new ContainerProvider())->resolve();

        self::assertInstanceOf(EmptyContainer::class, $container);
        self::assertFalse($container->has('missing'));
        try {
            $container->get('missing');
            self::fail('Expected a not-found exception.');
        } catch (NotFoundExceptionInterface $exception) {
            self::assertStringContainsString('missing', $exception->getMessage());
        }
    }

    public function testSerializationIsRejected(): void
    {
        $this->expectException(LogicException::class);
        serialize(new ContainerProvider());
    }
}
