<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Trigger;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\{SchedulerConfig, TriggerDefinition};
use Yiisoft\Scheduler\Executor\ShellCommand;
use Yiisoft\Scheduler\Trigger\{CronExpressionTrigger, PeriodicTrigger, TriggerFactory, TriggerInterface};
use stdClass;
use PHPUnit\Framework\Attributes\DataProvider;

use const DATE_ATOM;

final class TriggerFactoryTest extends TestCase
{
    public function testCustomFactoryReceivesScalarDefinitionAndBuilderKeepsItPortable(): void
    {
        $reference = 's13-custom-' . bin2hex(random_bytes(4));
        $received = null;
        TriggerFactory::register($reference, static function (array $definition) use (&$received): TriggerInterface {
            $received = $definition;
            return new PeriodicTrigger(60);
        });

        $definition = new TriggerDefinition($reference, ['answer' => 42, 'label' => 'portable']);
        $trigger = TriggerFactory::create($definition);
        self::assertInstanceOf(TriggerInterface::class, $trigger);
        self::assertSame(['answer' => 42, 'label' => 'portable'], $received);
        self::assertStringNotContainsString('PeriodicTrigger', serialize($definition));

        $builder = (new SchedulerConfig())->periodic('trigger-task', new ShellCommand('/bin/true'))->trigger($reference, ['answer' => 42]);
        self::assertSame($reference, $builder->trigger->reference);
        self::assertSame(['answer' => 42], $builder->trigger->definition);
    }

    public function testCustomFactoryRejectsInvalidResult(): void
    {
        $reference = 's13-invalid-' . bin2hex(random_bytes(4));
        TriggerFactory::register($reference, static fn(array $definition): object => new stdClass());

        $this->expectException(InvalidArgumentException::class);
        TriggerFactory::create(new TriggerDefinition($reference, []));
    }

    public function testCustomFactoryRejectsObjectPayloadBeforeInvocation(): void
    {
        $reference = 's13-object-payload';
        $received = null;
        TriggerFactory::register($reference, static function (array $definition) use (&$received): TriggerInterface {
            $received = $definition;
            return new PeriodicTrigger(60);
        });

        try {
            TriggerFactory::create(['type' => $reference, 'payload' => new stdClass()]);
            self::fail('Expected InvalidArgumentException for an object payload.');
        } catch (InvalidArgumentException) {
            self::assertNull($received);
        }
    }

    public function testBuildsPortablePeriodicDescriptor(): void
    {
        $trigger = TriggerFactory::create(['type' => 'periodic', 'seconds' => 60, 'from' => '2025-01-01T00:00:00Z']);
        self::assertInstanceOf(PeriodicTrigger::class, $trigger);
    }

    public function testBuildsCronAndRejectsIncompleteDescriptor(): void
    {
        self::assertInstanceOf(CronExpressionTrigger::class, TriggerFactory::create(['type' => 'cron', 'expression' => '* * * * *', 'timezone' => 'UTC']));
        $this->expectException(InvalidArgumentException::class);
        TriggerFactory::create(['type' => 'periodic']);
    }

    public function testRejectsMismatchedReference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TriggerFactory::create(new TriggerDefinition('cron', ['type' => 'periodic', 'seconds' => 1]));
    }

    public function testRejectsUnknownReference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TriggerFactory::create(new TriggerDefinition('custom', ['type' => 'periodic', 'seconds' => 1]));
    }

    public function testRejectsNonUtcBounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TriggerFactory::create(['type' => 'periodic', 'seconds' => 1, 'from' => (new DateTimeImmutable())->format(DATE_ATOM)]);
    }

    #[DataProvider('nonCanonicalBounds')]
    public function testRejectsNonCanonicalBounds(string $bound): void
    {
        $this->expectException(InvalidArgumentException::class);
        TriggerFactory::create(['type' => 'periodic', 'seconds' => 1, 'from' => $bound]);
    }

    public static function nonCanonicalBounds(): array
    {
        return [
            ['2026-1-01T00:00:00Z'],
            ['2026-01-1T00:00:00Z'],
            ['2026-01-01T0:00:00Z'],
        ];
    }

    public function testAcceptsCanonicalBound(): void
    {
        self::assertInstanceOf(
            PeriodicTrigger::class,
            TriggerFactory::create(['type' => 'periodic', 'seconds' => 1, 'from' => '2026-01-01T00:00:00Z']),
        );
    }
}
