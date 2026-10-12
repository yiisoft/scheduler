<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Callable;

use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Callable\CallableFactory;
use Yiisoft\Scheduler\Tests\Support\StubContainer;
use InvalidArgumentException;

interface FactoryDependency
{
    public function format(string $value): string;
}

final class CallableFactoryTest extends TestCase
{
    public function testInjectsTypedDependencyAndNamedScalar(): void
    {
        $dependency = new class implements FactoryDependency {
            public function format(string $value): string
            {
                return 'ok:' . $value;
            }
        };
        $callable = (new CallableFactory(new StubContainer([FactoryDependency::class => $dependency])))
            ->create(static fn(FactoryDependency $dependency, string $value): string => $dependency->format($value));

        self::assertSame('ok:value', $callable(value: 'value'));
    }

    public function testAcceptsActualCallableDeclarationsAndEmptyArguments(): void
    {
        $factory = new CallableFactory(new StubContainer());
        $object = new class {
            public function __invoke(): string
            {
                return 'invokable';
            }

            public static function named(): string
            {
                return 'static';
            }
        };

        self::assertSame('invokable', $factory->create($object)());
        self::assertSame('static', $factory->create($object::named(...))());
        self::assertSame('function', $factory->create(static fn(): string => 'function')());
    }

    public function testRejectsPositionalArguments(): void
    {
        $callable = (new CallableFactory(new StubContainer()))->create(static fn(string $value): string => $value);
        $this->expectException(InvalidArgumentException::class);
        $callable('positional');
    }
}
