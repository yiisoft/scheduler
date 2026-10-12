<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Executor;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Schedule\Executor\{Command, CommandExecutorMap, ExecutionContext, ExecutorInterface};
use Yiisoft\Schedule\Integration\Async\ExecutionOutcome;
use stdClass;

final class CommandExecutorMapTest extends TestCase
{
    public function testLookupUsesExactCommandClassWithoutSubclassOrInterfaceFallback(): void
    {
        $executor = new class implements ExecutorInterface {
            public function execute(ExecutionContext $context): ExecutionOutcome
            {
                return new ExecutionOutcome(ExecutionOutcome::SUCCEEDED);
            }
        };
        $map = new CommandExecutorMap([MapCommand::class => $executor]);

        self::assertSame($executor, $map->executorFor(new MapCommand()));

        $this->expectException(InvalidArgumentException::class);
        $map->executorFor(new MapCommandChild());
    }

    public function testInterfaceCommandLookupDoesNotFallback(): void
    {
        $executor = new class implements ExecutorInterface {
            public function execute(ExecutionContext $context): ExecutionOutcome
            {
                return new ExecutionOutcome(ExecutionOutcome::SUCCEEDED);
            }
        };
        $map = new CommandExecutorMap([MapCommand::class => $executor]);

        $this->expectException(InvalidArgumentException::class);
        $map->executorFor(new MapOtherCommand());
    }

    public function testInvalidKeysAndValuesAreRejected(): void
    {
        $executor = new class implements ExecutorInterface {
            public function execute(ExecutionContext $context): ExecutionOutcome
            {
                return new ExecutionOutcome(ExecutionOutcome::SUCCEEDED);
            }
        };

        $rejected = 0;
        foreach ([['not-a-command' => $executor], [MapCommandContract::class => $executor], [MapAbstractCommand::class => $executor], [MapCommand::class => new stdClass()]] as $map) {
            try {
                new CommandExecutorMap($map);
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(4, $rejected);
    }
}

class MapCommand implements Command {}
final class MapCommandChild extends MapCommand {}
final class MapOtherCommand implements Command {}
interface MapCommandContract extends Command {}
abstract class MapAbstractCommand implements Command {}
