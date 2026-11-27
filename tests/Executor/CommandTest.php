<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Executor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Executor\CallableCommand;
use Yiisoft\Scheduler\Executor\PhpCommand;
use Yiisoft\Scheduler\Executor\ProcessCommand;
use Yiisoft\Scheduler\RecurringTask;

use function sprintf;

use const PHP_BINARY;

/**
 * What a command is before anything runs it: a value that refuses what it cannot carry, and
 * says what it is well enough for a task to be told apart from another.
 */
final class CommandTest extends TestCase
{
    /**
     * @return iterable<string, array{callable(): mixed, string}>
     */
    public static function refusedCommands(): iterable
    {
        yield 'no program' => [static fn() => new ProcessCommand(''), 'The program to run cannot be empty'];
        yield 'NUL in the program' => [static fn() => new ProcessCommand("php\0"), 'cannot hold a NUL byte'];
        yield 'arguments out of order' => [
            static fn() => new ProcessCommand('php', [1 => '-v']),
            'arguments of a program are an ordered list',
        ];
        yield 'an argument that is not a string' => [
            static fn() => new ProcessCommand('php', [17]),
            'Argument 0 is of type "int"',
        ];
        yield 'NUL in an argument' => [static fn() => new ProcessCommand('php', ["-v\0"]), 'Argument 0 cannot hold'];
        yield 'an environment variable with no name' => [
            static fn() => new ProcessCommand('php', [], ['' => 'value']),
            'An environment variable name cannot be empty',
        ];
        yield 'an "=" in an environment variable name' => [
            static fn() => new ProcessCommand('php', [], ['TZ=UTC' => 'Europe/Skopje']),
            'cannot hold an "="',
        ];
        yield 'an environment value that is not a string' => [
            static fn() => new ProcessCommand('php', [], ['COUNT' => 3]),
            'named by strings and hold strings',
        ];
        yield 'no PHP binary' => [static fn() => new PhpCommand('run.php', [], ''), 'PHP binary cannot be empty'];
        yield 'options out of order' => [
            static fn() => new PhpCommand('run.php', [], null, [2 => '-n']),
            'arguments for PHP itself are an ordered list',
        ];
        yield 'no service' => [static fn() => CallableCommand::of(''), 'A service and a method are both needed'];
    }

    /**
     * @param callable(): mixed $build
     */
    #[DataProvider('refusedCommands')]
    public function testACommandRefusesWhatItCannotRun(callable $build, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        $build();
    }

    public function testTwoProgramsDifferingOnlyInAnArgumentAreTwoTasks(): void
    {
        $yesterday = RecurringTask::cron('@daily', new ProcessCommand('/usr/bin/report', ['--since=yesterday']));
        $lastWeek = RecurringTask::cron('@daily', new ProcessCommand('/usr/bin/report', ['--since=last-week']));

        $this->assertNotSame($yesterday->getId(), $lastWeek->getId());
    }

    public function testTheSameProgramDeclaredTwiceIsOneTask(): void
    {
        $this->assertSame(
            RecurringTask::cron('@daily', new ProcessCommand('/usr/bin/report', ['--now']))->getId(),
            RecurringTask::cron('@daily', new ProcessCommand('/usr/bin/report', ['--now']))->getId(),
        );
    }

    public function testAProgramAndAPhpScriptOfTheSameNameAreTwoTasks(): void
    {
        $this->assertNotSame(
            RecurringTask::cron('@daily', new ProcessCommand('report.php'))->getId(),
            RecurringTask::cron('@daily', new PhpCommand('report.php'))->getId(),
        );
    }

    public function testAPhpCommandIsDescribedWithoutTheBinaryItInherits(): void
    {
        // The binary is the one of whichever process runs the command, so naming it here would
        // move the identifier of the task from one machine to the next.
        $this->assertStringNotContainsString(PHP_BINARY, (string) new PhpCommand('report.php'));
        $this->assertSame("php('report.php')", (string) new PhpCommand('report.php'));
    }

    public function testACommandSaysWhatItIsWithItsWholeDeclaration(): void
    {
        $command = new ProcessCommand('/usr/bin/report', ['--now'], ['TZ' => 'UTC'], '/srv/app');

        $this->assertSame("process('/usr/bin/report' '--now', TZ='UTC', in '/srv/app')", (string) $command);
    }

    public function testACallableCommandSaysWhereItWasDeclared(): void
    {
        $line = __LINE__ + 1;
        $command = CallableCommand::call(static fn() => null);

        $this->assertSame(sprintf('callable(closure@%s:%d)', __FILE__, $line), (string) $command);
    }

    public function testACallableCommandSaysWhichServiceItCalls(): void
    {
        $this->assertSame('callable(App\\Report::send)', (string) CallableCommand::of('App\\Report', 'send'));
    }

    public function testTheSameEnvironmentWrittenInAnotherOrderIsOneTask(): void
    {
        $this->assertSame(
            RecurringTask::cron('@daily', new ProcessCommand('/usr/bin/report', [], ['TZ' => 'UTC', 'LANG' => 'C']))->getId(),
            RecurringTask::cron('@daily', new ProcessCommand('/usr/bin/report', [], ['LANG' => 'C', 'TZ' => 'UTC']))->getId(),
        );
    }

    public function testTwoOptionListsThatReadAlikeAreStillTwoTasks(): void
    {
        $this->assertNotSame(
            (string) new PhpCommand('report.php', [], null, ['-d', 'memory_limit=1G']),
            (string) new PhpCommand('report.php', [], null, ['-d memory_limit=1G']),
        );
    }

    public function testTwoClosuresOfOneFileAreTwoTasks(): void
    {
        $first = CallableCommand::call(static fn() => null);
        $second = CallableCommand::call(static fn() => null);

        $this->assertNotSame((string) $first, (string) $second, 'the line they are written on tells them apart');
        $this->assertNotSame(
            RecurringTask::cron('@daily', $first)->getId(),
            RecurringTask::cron('@daily', $second)->getId(),
        );
    }
}
