<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Executor;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Executor\ShellCommand;
use Yiisoft\Scheduler\Executor\ShellVariableOverrides;
use Yiisoft\Scheduler\Executor\ShellVariableResolver;
use LogicException;

final class ShellCommandTest extends TestCase
{
    public function testSerializationIsRejected(): void
    {
        $this->expectException(LogicException::class);
        serialize(new ShellCommand('/bin/echo'));
    }

    public function testDirectCommandCompilesAndResolvesPrecedence(): void
    {
        $reference = new ShellCommand('/bin/echo', ['--name=${NAME}', '\\${NAME}', 'a b', '']);
        $resolved = (new ShellVariableResolver())->resolve(
            $reference->specification(),
            new ShellVariableOverrides(['NAME' => 'override']),
            ['NAME' => 'environment'],
        );

        self::assertSame('/bin/echo', $resolved->executable);
        self::assertSame(['--name=override', '${NAME}', 'a b', ''], $resolved->argv);
    }

    public function testMissingAndMalformedVariablesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ShellCommand('echo', ['${MISSING}']);
        (new ShellVariableResolver())->resolve(
            (new ShellCommand('echo', ['${MISSING}']))->specification(),
            new ShellVariableOverrides(),
        );
    }

    public function testDuplicateOverridesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ShellVariableOverrides::fromArguments(['--var', 'A=1', '--var=A=2']);
    }

    public function testMalformedOverrideArgumentArraysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ShellVariableOverrides::fromArguments([1 => '--var=A=1']);
    }

    public function testBareAndMalformedPlaceholdersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $command = new ShellCommand('echo', ['$NAME']);
        (new ShellVariableResolver())->resolve($command->specification(), new ShellVariableOverrides());
    }

    public function testRepeatedExpansionIsBounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $command = new ShellCommand('echo', [str_repeat('${A}', 10000)]);
        (new ShellVariableResolver())->resolve($command->specification(), new ShellVariableOverrides(['A' => str_repeat('x', 65536)]));
    }

    public function testNulBytesFromEnvironmentAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ShellVariableResolver())->resolve(
            (new ShellCommand('echo', ['${VALUE}']))->specification(),
            new ShellVariableOverrides(),
            ['VALUE' => "value\0"],
        );
    }
}
