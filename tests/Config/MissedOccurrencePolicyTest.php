<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Config;

use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\{ConfigurationConverter,MissedOccurrencePolicy,MissedOccurrenceSetting,OverlapPolicy,PreparedConfiguration,SchedulerConfig,TaskDefinition};
use Yiisoft\Scheduler\Executor\ShellCommand;
use Yiisoft\Scheduler\Task\TaskId;

final class MissedOccurrencePolicyTest extends TestCase
{
    public function testTypedPoliciesPropagate(): void
    {
        $c = new SchedulerConfig();
        $c->periodic(new ShellCommand('/bin/echo'))->every(60)->repeatMissedAll(2)->id('all');
        $c->periodic(new ShellCommand('/bin/echo'))->every(60)->missedOccurrences(MissedOccurrencePolicy::LAST)->id('last');
        $p = $this->convert($c);
        self::assertSame(MissedOccurrencePolicy::ALL, $p->task('all')->missedOccurrences->policy);
        self::assertSame(2, $p->task('all')->missedOccurrences->max);
        self::assertSame(MissedOccurrencePolicy::LAST, $p->task('last')->missedOccurrences->policy);
    }

    public function testPreparedFingerprintIncludesPolicies(): void
    {
        $command = new ShellCommand('/bin/echo');
        $base = new PreparedConfiguration([new TaskDefinition(new TaskId('task'), $command)]);
        $changed = new PreparedConfiguration([new TaskDefinition(new TaskId('task'), $command, overlap: OverlapPolicy::SKIP)]);
        self::assertNotSame($base->fingerprint(), $changed->fingerprint());
    }

    public function testAllSettingExposesPositiveMax(): void
    {
        $s = new MissedOccurrenceSetting(MissedOccurrencePolicy::ALL, 2);
        self::assertSame(2, $s->max);
    }

    private function convert(SchedulerConfig $c): PreparedConfiguration
    {
        return (new ConfigurationConverter())->convert($c, new RuntimeFactoryFixture());
    }
}
