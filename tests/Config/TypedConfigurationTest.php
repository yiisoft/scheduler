<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\{ConfigurationConverter,SchedulerConfig};
use Yiisoft\Scheduler\Executor\{CallableCommand,ShellCommand};
use Yiisoft\Scheduler\ExecutionMode;
use Yiisoft\Scheduler\Config\PreparedConfiguration;
use ArgumentCountError;

final class TypedConfigurationTest extends TestCase
{
    public function testDirectDslCommandsAndDefaultAsync(): void
    {
        $c = new SchedulerConfig();
        $c->periodic('shell', new ShellCommand('/bin/echo', ['ok']))->every(60);
        $p = $this->convert($c);
        self::assertSame(ExecutionMode::ASYNC, $p->task('shell')->mode);
        self::assertInstanceOf(ShellCommand::class, $p->task('shell')->command);
    }

    public function testEveryAndCronPreserveTriggerReferences(): void
    {
        $c = new SchedulerConfig();
        $c->every('every-task', new ShellCommand('/bin/echo'), 60);
        $c->cron('cron-task', new CallableCommand(static function (): void {}), '* * * * *');
        $tasks = array_values($this->convert($c)->tasks);
        self::assertSame('periodic', $tasks[0]->trigger->reference);
        self::assertSame('cron', $tasks[1]->trigger->reference);
    }

    public function testExplicitSyncAndTimeoutAndStableIds(): void
    {
        $c = new SchedulerConfig();
        $c->periodic('stable', new ShellCommand('/bin/echo'))->every(60)->sync();
        $c->periodic('second', new ShellCommand('/bin/echo'))->every(60)->timeout(1);
        $p = $this->convert($c);
        self::assertSame(ExecutionMode::SYNC, $p->task('stable')->mode);
        self::assertSame(1.0, $p->task('second')->timeout->softSeconds);
    }

    public function testDuplicateStableIdsAreRejected(): void
    {
        $c = new SchedulerConfig();
        $c->periodic('same', new ShellCommand('/bin/echo'))->every(60);
        $c->periodic('same', new ShellCommand('/bin/echo'))->every(60);
        $this->expectException(InvalidArgumentException::class);
        $this->convert($c);
    }

    public function testIncompletePeriodicDescriptorIsRejected(): void
    {
        $c = new SchedulerConfig();
        $this->expectException(ArgumentCountError::class);
        $c->periodic();
    }

    public function testDeclarationOrderDoesNotChangeExplicitIdentity(): void
    {
        $first = new SchedulerConfig();
        $first->periodic('alpha', new ShellCommand('/bin/echo'))->every(60);
        $first->periodic('beta', new ShellCommand('/bin/echo'))->every(120);

        $second = new SchedulerConfig();
        $second->periodic('beta', new ShellCommand('/bin/echo'))->every(120);
        $second->periodic('alpha', new ShellCommand('/bin/echo'))->every(60);

        self::assertSame(['alpha', 'beta'], array_keys($this->convert($first)->tasks));
        self::assertSame(['beta', 'alpha'], array_keys($this->convert($second)->tasks));
    }

    private function convert(SchedulerConfig $c): PreparedConfiguration
    {
        return (new ConfigurationConverter())->convert($c, new RuntimeFactoryFixture());
    }
}
