<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\{PhpConfigurationSource, TimeoutPolicy};
use Yiisoft\Scheduler\Callable\ContainerProvider;
use Yiisoft\Scheduler\Executor\{CallableCommand, CallableExecutor, CommandExecutorMap, CommandExecutorRuntime, CommandExecutorRuntimeFactory, ExecutionContext};
use Yiisoft\Scheduler\Integration\Async\{ExecutionIdentity, ExecutionOutcome, PreparedConfigurationExecutionBindingSource};

use function strlen;

final class PreparedConfigurationExecutionBindingSourceTest extends TestCase
{
    public function testMapsConfigurationFingerprintToStableRecordCompatibleBinding(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'schedule-binding-');
        file_put_contents($path, '<?php return static function (): \\Yiisoft\\Scheduler\\Config\\SchedulerConfig { $config = new \\Yiisoft\\Scheduler\\Config\\SchedulerConfig(); $config->timeout(1.25, 2.5); $config->periodic(new \\Yiisoft\\Scheduler\\Executor\\CallableCommand(static fn(): \\Yiisoft\\Scheduler\\Integration\\Async\\ExecutionOutcome => new \\Yiisoft\\Scheduler\\Integration\\Async\\ExecutionOutcome("succeeded")))->every(60)->id("task")->overlap("skip"); return $config; };');
        $source = new PhpConfigurationSource($path);
        $configuration = $source->prepare(new BindingRuntimeFactory());
        $bindings = new PreparedConfigurationExecutionBindingSource($source, $configuration, 'owner-run');

        $first = $bindings->map('default', 'task', 'o0000000001', 1_700_000_000);
        $second = $bindings->map('default', 'task', 'o0000000001', 1_700_000_000);

        self::assertSame($first->correlation->executionId, $second->correlation->executionId);
        self::assertSame($configuration->fingerprint(), $first->configurationFingerprint);
        self::assertSame($configuration->taskPolicyProjection($configuration->tasks['task']), $first->payload);
        self::assertSame(64, strlen($configuration->taskFingerprint($configuration->tasks['task'])));
        self::assertSame(['sourceId' => $source->sourceId(), 'sourceRevision' => $source->revision()], $first->context);
        self::assertSame($configuration->taskPolicyProjection($configuration->tasks['task']), $first->payload);
        self::assertSame('async', $first->mode);
        self::assertSame(1.25, $first->timeout->softSeconds);
        self::assertSame(2.5, $first->timeout->hardSeconds);
        self::assertInstanceOf(ExecutionIdentity::class, $first->correlation);
        self::assertInstanceOf(TimeoutPolicy::class, $first->timeout);
        self::assertIsString($first->mode);
        self::assertFalse(property_exists($first, 'profile'));
        self::assertSame('default', $first->scheduleKey);
    }

    public function testRejectsUnknownTaskAndWrongSchedule(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'schedule-binding-');
        file_put_contents($path, '<?php return static function (): \\Yiisoft\\Scheduler\\Config\\SchedulerConfig { $config = new \\Yiisoft\\Scheduler\\Config\\SchedulerConfig(); $config->periodic(new \\Yiisoft\\Scheduler\\Executor\\CallableCommand(static fn(): \\Yiisoft\\Scheduler\\Integration\\Async\\ExecutionOutcome => new \\Yiisoft\\Scheduler\\Integration\\Async\\ExecutionOutcome("succeeded")))->every(60)->id("task"); return $config; };');
        $source = new PhpConfigurationSource($path);
        $bindings = new PreparedConfigurationExecutionBindingSource($source, $source->prepare(new BindingRuntimeFactory()), 'owner-run');

        $this->expectException(InvalidArgumentException::class);
        $bindings->map('other', 'unknown', 'occurrence', 1);
    }
}

final class BindingRuntimeFactory implements CommandExecutorRuntimeFactory
{
    public function create(ContainerProvider $containerProvider): CommandExecutorRuntime
    {
        $map = new CommandExecutorMap([CallableCommand::class => new CallableExecutor($containerProvider)]);
        return new class ($map) implements CommandExecutorRuntime {
            public function __construct(private readonly CommandExecutorMap $map) {}

            public function commandExecutorMap(): CommandExecutorMap
            {
                return $this->map;
            }

            public function execute(ExecutionContext $context): ExecutionOutcome
            {
                return $this->map->executorFor($context->command)->execute($context);
            }
        };
    }
}
