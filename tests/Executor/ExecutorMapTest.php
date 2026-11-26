<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Executor;

use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Executor\CallableCommand;
use Yiisoft\Scheduler\Executor\CallableExecutor;
use Yiisoft\Scheduler\Executor\CommandInterface;
use Yiisoft\Scheduler\Executor\ExecutorInterface;
use Yiisoft\Scheduler\Executor\ExecutorMap;
use Yiisoft\Scheduler\Executor\PhpCommand;
use Yiisoft\Scheduler\Executor\PhpExecutor;
use Yiisoft\Scheduler\Executor\ProcessCommand;
use Yiisoft\Scheduler\Executor\ProcessExecutor;
use Yiisoft\Scheduler\TaskContext;
use Yiisoft\Scheduler\Trigger\CronExpressionTrigger;
use DateTimeImmutable;

/**
 * Which executor a command goes to, and what happens to one that goes nowhere.
 */
final class ExecutorMapTest extends TestCase
{
    public function testACommandGoesToTheExecutorRegisteredForItsClass(): void
    {
        $map = ExecutorMap::standard();

        $this->assertInstanceOf(CallableExecutor::class, $map->for(CallableCommand::call(static fn() => null)));
        $this->assertInstanceOf(ProcessExecutor::class, $map->for(new ProcessCommand('php')));
        $this->assertInstanceOf(PhpExecutor::class, $map->for(new PhpCommand('run.php')));
    }

    public function testACommandClassNobodyRegisteredIsAnErrorRatherThanAGuess(): void
    {
        $map = new ExecutorMap([ProcessCommand::class => new ProcessExecutor()]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No executor is registered for "' . PhpCommand::class . '". Registered: ' . ProcessCommand::class);

        $map->for(new PhpCommand('run.php'));
    }

    public function testAnEmptyMapSaysSoRatherThanListingNothing(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Registered: nothing.');

        (new ExecutorMap())->for(new ProcessCommand('php'));
    }

    public function testASubclassIsACommandOfItsOwn(): void
    {
        $executor = $this->counting();
        $map = new ExecutorMap([CountingCommand::class => $executor]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No executor is registered for "' . SpecialCountingCommand::class . '"');

        $map->for(new SpecialCountingCommand());
    }

    public function testACommandKindOfTheApplicationsOwnIsRunByItsOwnExecutor(): void
    {
        $executor = $this->counting();
        $map = ExecutorMap::standard()->with([CountingCommand::class => $executor]);

        $map->for(new CountingCommand())->execute(new CountingCommand(), $this->context());

        $this->assertSame(1, $executor->ran);
        $this->assertInstanceOf(ProcessExecutor::class, $map->for(new ProcessCommand('php')));
    }

    public function testARegistrationReplacesTheOneItShares(): void
    {
        $executor = $this->counting();
        $map = ExecutorMap::standard()->with([ProcessCommand::class => $executor]);

        $this->assertSame($executor, $map->for(new ProcessCommand('php')));
    }

    public function testOnlyACommandClassCanBeRegistered(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not implement "' . CommandInterface::class . '"');

        /** @psalm-suppress InvalidArgument */
        new ExecutorMap([DateTimeImmutable::class => $this->counting()]);
    }

    public function testAnAbstractClassIsNotAClassCommandsAreOf(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not a class commands are of');

        new ExecutorMap([AbstractCommand::class => $this->counting()]);
    }

    private function counting(): ExecutorInterface
    {
        return new class implements ExecutorInterface {
            public int $ran = 0;

            public function execute(CommandInterface $command, TaskContext $context): mixed
            {
                ++$this->ran;

                return null;
            }
        };
    }

    private function context(): TaskContext
    {
        return new TaskContext('default', 'task', new CronExpressionTrigger('* * * * *'), new DateTimeImmutable(), null);
    }
}

abstract class AbstractCommand implements CommandInterface {}

class CountingCommand implements CommandInterface
{
    public function __toString(): string
    {
        return 'counting()';
    }
}

final class SpecialCountingCommand extends CountingCommand
{
    public function __toString(): string
    {
        return 'counting(special)';
    }
}
