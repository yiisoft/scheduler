<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Executor;

use LogicException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Executor\CallableCommand;

final class CallableCommandTest extends TestCase
{
    public function testClosureAndInvokableObjectAreKeptForAuthoring(): void
    {
        $closure = static fn(): string => 'ok';
        $object = new class {
            public function __invoke(): string
            {
                return 'ok';
            }
        };

        self::assertSame('ok', ((new CallableCommand($closure))->callable())());
        self::assertSame('ok', ((new CallableCommand($object))->callable())());
    }

    public function testNamedArgumentsAreKeptWithTheInstanceCallable(): void
    {
        $command = new CallableCommand(static fn(string $message, int $count): string => $message . $count, ['message' => 'ok', 'count' => 2]);

        self::assertSame(['message' => 'ok', 'count' => 2], $command->arguments());
        self::assertSame('ok2', ($command->callable())(...array_values($command->arguments())));
    }

    public function testSerializationIsRejected(): void
    {
        $this->expectException(LogicException::class);

        serialize(new CallableCommand(static fn(): string => 'secret'));
    }
}
