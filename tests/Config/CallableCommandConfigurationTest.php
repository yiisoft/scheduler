<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Schedule\Config\{ConfigurationConverter,SchedulerConfig};
use Yiisoft\Schedule\Executor\CallableCommand;
use Yiisoft\Schedule\ExecutionMode;
use Yiisoft\Schedule\Config\PreparedConfiguration;

final class CallableCommandConfigurationTest extends TestCase
{
    public function testCallableIsAProcessLocalDirectTask(): void
    {
        $command = new CallableCommand(static fn(string $value): string => $value, ['value' => 'ok']);
        $config = new SchedulerConfig();
        $config->periodic($command)->every(60)->id('named')->sync();
        $task = $this->convert($config)->task('named');
        self::assertSame($command, $task->command);
        self::assertSame(['value' => 'ok'], $command->arguments());
        self::assertSame(ExecutionMode::SYNC, $task->mode);
    }

    public function testCallableRequiresNoRegistryOrSyntheticReference(): void
    {
        $config = new SchedulerConfig();
        $config->periodic(new CallableCommand(static fn(): string => 'ok'))->every(60);
        $prepared = $this->convert($config);
        self::assertInstanceOf(CallableCommand::class, array_values($prepared->tasks)[0]->command);
    }

    public function testDuplicateTaskIdsAreRejected(): void
    {
        $config = new SchedulerConfig();
        $config->periodic(new CallableCommand(static fn(): string => 'one'))->every(60)->id('same');
        $config->periodic(new CallableCommand(static fn(): string => 'two'))->every(120)->id('same');
        $this->expectException(InvalidArgumentException::class);
        $this->convert($config);
    }

    private function convert(SchedulerConfig $config): PreparedConfiguration
    {
        return (new ConfigurationConverter())->convert($config, new RuntimeFactoryFixture());
    }
}
