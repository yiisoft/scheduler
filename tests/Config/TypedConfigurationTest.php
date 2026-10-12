<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\{ConfigurationConverter,SchedulerConfig};
use Yiisoft\Scheduler\Executor\{CallableCommand,ShellCommand};
use Yiisoft\Scheduler\ExecutionMode;
use Yiisoft\Scheduler\Config\PreparedConfiguration;

final class TypedConfigurationTest extends TestCase
{
    public function testDirectDslCommandsAndDefaultAsync(): void
    {
        $c = new SchedulerConfig();
        $c->periodic(new ShellCommand('/bin/echo', ['ok']))->every(60)->id('shell');
        $p = $this->convert($c);
        self::assertSame(ExecutionMode::ASYNC, $p->task('shell')->mode);
        self::assertInstanceOf(ShellCommand::class, $p->task('shell')->command);
    }

    public function testEveryAndCronPreserveTriggerReferences(): void
    {
        $c = new SchedulerConfig();
        $c->every(new ShellCommand('/bin/echo'), 60);
        $c->cron(new CallableCommand(static function (): void {}), '* * * * *');
        $tasks = array_values($this->convert($c)->tasks);
        self::assertSame('periodic', $tasks[0]->trigger->reference);
        self::assertSame('cron', $tasks[1]->trigger->reference);
    }

    public function testExplicitSyncAndTimeoutAndStableIds(): void
    {
        $c = new SchedulerConfig();
        $c->periodic(new ShellCommand('/bin/echo'))->every(60)->id('stable')->sync();
        $c->periodic(new ShellCommand('/bin/echo'))->every(60)->id('second')->timeout(1);
        $p = $this->convert($c);
        self::assertSame(ExecutionMode::SYNC, $p->task('stable')->mode);
        self::assertSame(1.0, $p->task('second')->timeout->softSeconds);
    }

    public function testDuplicateStableIdsAreRejected(): void
    {
        $c = new SchedulerConfig();
        $c->periodic(new ShellCommand('/bin/echo'))->every(60)->id('same');
        $c->periodic(new ShellCommand('/bin/echo'))->every(60)->id('same');
        $this->expectException(InvalidArgumentException::class);
        $this->convert($c);
    }

    public function testIncompletePeriodicDescriptorIsRejected(): void
    {
        $c = new SchedulerConfig();
        $c->periodic(new ShellCommand('/bin/echo'));
        $this->expectException(InvalidArgumentException::class);
        $this->convert($c);
    }

    private function convert(SchedulerConfig $c): PreparedConfiguration
    {
        return (new ConfigurationConverter())->convert($c, new RuntimeFactoryFixture());
    }
}
