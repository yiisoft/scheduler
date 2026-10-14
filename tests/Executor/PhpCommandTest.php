<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Executor;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Executor\{PhpCommand,PhpProcessConfiguration,ShellCommand};

final class PhpCommandTest extends TestCase
{
    public function testResolvesInheritedBinaryAndStartupArguments(): void
    {
        $command = new PhpCommand(new ShellCommand('/app/task.php', ['--name', '${NAME}'], ['NAME' => 'value']));

        $resolved = $command->resolve(new PhpProcessConfiguration('/usr/bin/php81', ['-d', 'memory_limit=1G', '-c', '/etc/php.ini']));

        self::assertSame('/usr/bin/php81', $resolved->executable);
        self::assertSame(
            ['-d', 'memory_limit=1G', '-c', '/etc/php.ini', '/app/task.php', '--name', '${NAME}'],
            $resolved->argv,
        );
        self::assertSame(['NAME' => 'value'], $resolved->variables);
    }

    public function testAnEmptyArgumentOverrideReplacesInheritedArguments(): void
    {
        $command = new PhpCommand(
            new ShellCommand('/app/task.php'),
            phpArguments: [],
        );

        $resolved = $command->resolve(new PhpProcessConfiguration('/usr/bin/php', ['-d', 'x=1']));

        self::assertSame('/usr/bin/php', $resolved->executable);
        self::assertSame(['/app/task.php'], $resolved->argv);
    }

    public function testBinaryAndArgumentOverridesAreIndependent(): void
    {
        $command = new PhpCommand(
            new ShellCommand('/app/task.php', ['arg']),
            phpBinary: '/opt/php',
            phpArguments: ['-n'],
        );

        $resolved = $command->resolve(new PhpProcessConfiguration('/usr/bin/php', ['-d', 'x=1']));

        self::assertSame('/opt/php', $resolved->executable);
        self::assertSame(['-n', '/app/task.php', 'arg'], $resolved->argv);
    }

    public function testInvalidOverridesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PhpCommand(new ShellCommand('/app/task.php'), phpArguments: [1 => '-n']);
    }

    public function testSerializationIsRejected(): void
    {
        $this->expectException(LogicException::class);
        serialize(new PhpCommand(new ShellCommand('/app/task.php')));
    }
}
