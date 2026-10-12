<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Config;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Schedule\Callable\{ContainerProvider};
use Yiisoft\Schedule\Config\{ConfigurationConverter,PreparedConfiguration,SchedulerConfig};
use Yiisoft\Schedule\Executor\{CallableCommand,ShellCommand};
use Yiisoft\Schedule\ExecutionMode;

final class ConfigurationConverterTest extends TestCase
{
    public function testConvertsDirectCommandsAndPreservesPolicy(): void
    {
        $config = new SchedulerConfig();
        $shell = new ShellCommand('/bin/echo', ['ok']);
        $callable = new CallableCommand(static fn(): string => 'ok');
        $config->periodic($shell)->every(60)->id('shell')->metadata(['owner' => 'test']);
        $config->periodic($callable)->every(120)->id('callable')->sync();
        $prepared = $this->convert($config);
        self::assertSame(['shell','callable'], array_keys($prepared->tasks));
        self::assertSame($shell, $prepared->task('shell')->command);
        self::assertSame(ExecutionMode::SYNC, $prepared->task('callable')->mode);
        self::assertSame(['owner' => 'test'], $prepared->task('shell')->metadata);
    }

    public function testRuntimeFactoryProvidesExactBuiltInMap(): void
    {
        $runtime = (new RuntimeFactoryFixture())->create(new ContainerProvider());
        self::assertCount(2, $runtime->commandExecutorMap()->all());
        self::assertArrayHasKey(ShellCommand::class, $runtime->commandExecutorMap()->all());
        self::assertArrayHasKey(CallableCommand::class, $runtime->commandExecutorMap()->all());
    }

    public function testPreparedAndCallableSerializationBarriers(): void
    {
        $prepared = $this->convert((new SchedulerConfig()));
        try {
            serialize($prepared);
            self::fail('Prepared configuration must not serialize.');
        } catch (LogicException) {
            self::assertTrue(true);
        }
        try {
            serialize(new CallableCommand(static fn(): string => 'ok'));
            self::fail('Callable command must not serialize.');
        } catch (LogicException) {
            self::assertTrue(true);
        }
        try {
            serialize(new ShellCommand('/bin/echo'));
            self::fail('Shell command must not serialize.');
        } catch (LogicException) {
            self::assertTrue(true);
        }
        try {
            serialize(new ContainerProvider());
            self::fail('Container provider must not serialize.');
        } catch (LogicException) {
            self::assertTrue(true);
        }
    }

    public function testSyncTaskCannotHaveTimeout(): void
    {
        $config = new SchedulerConfig();
        $config->periodic(new ShellCommand('/bin/echo'))->every(60)->sync()->timeout(1);
        $this->expectException(InvalidArgumentException::class);
        $this->convert($config);
    }

    private function convert(SchedulerConfig $config): PreparedConfiguration
    {
        return (new ConfigurationConverter())->convert($config, new RuntimeFactoryFixture());
    }
}
