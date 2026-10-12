<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Binary;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Yiisoft\Scheduler\Binary\{CliArgumentParser, CliInvocation};

final class CliArgumentParserTest extends TestCase
{
    public function testPlannerAndHealthcheckShareStrictOptions(): void
    {
        $parser = new CliArgumentParser();
        $planner = $parser->parse(['--state-dir', '/tmp/state', '--config', '/tmp/c.php']);
        self::assertInstanceOf(CliInvocation::class, $planner);
        self::assertSame('planner', $planner->mode);
        self::assertSame('/tmp/c.php', $planner->config);
        self::assertSame('/tmp/state', $planner->state);

        $healthcheck = $parser->parse(['healthcheck', '--config=/tmp/c.php', '--state-dir=/tmp/state']);
        self::assertInstanceOf(CliInvocation::class, $healthcheck);
        self::assertSame('healthcheck', $healthcheck->mode);
        self::assertSame('/tmp/c.php', $healthcheck->config);
        self::assertSame('/tmp/state', $healthcheck->state);
    }

    public function testInvocationIsAnImmutableValueObjectWithOnlyStableFields(): void
    {
        $result = $this->parserResult();
        self::assertInstanceOf(CliInvocation::class, $result);
        $type = new ReflectionClass($result);
        self::assertSame('Yiisoft\\Scheduler\\Binary\\CliInvocation', $type->getName());
        self::assertTrue($type->isFinal());
        self::assertSame(['mode', 'config', 'state'], array_map(
            static fn($property): string => $property->getName(),
            $type->getProperties(),
        ));
        foreach (['mode', 'config', 'state'] as $property) {
            self::assertTrue($type->getProperty($property)->isPublic());
            self::assertTrue($type->getProperty($property)->isReadOnly());
        }
    }

    public function testRunModeKeepsConfigurationAndStateOutsideWorkerGrammar(): void
    {
        $result = (new CliArgumentParser())->parse(['run', 'task']);

        self::assertSame('run', $result->mode);
        self::assertSame('', $result->config);
        self::assertNull($result->state);
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidArgumentsAreRejectedBeforeConfiguration(string $argument): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CliArgumentParser())->parse(['--config', '/tmp/c.php', $argument]);
    }

    #[DataProvider('requiredOptionProvider')]
    public function testConfigurationOptionIsRequired(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CliArgumentParser())->parse($arguments);
    }

    /** @return array<string, array{list<string>}> */
    public static function requiredOptionProvider(): array
    {
        return [
            'planner without options' => [[]],
            'planner with state only' => [['--state-dir', '/tmp/state']],
            'healthcheck without config' => [['healthcheck']],
            'healthcheck with state only' => [['healthcheck', '--state-dir', '/tmp/state']],
        ];
    }

    #[DataProvider('missingValueProvider')]
    public function testOptionsRequireAValue(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CliArgumentParser())->parse($arguments);
    }

    /** @return array<string, array{list<string>}> */
    public static function missingValueProvider(): array
    {
        return [
            'config' => [['--config']],
            'state directory' => [['--state-dir']],
            'healthcheck config' => [['healthcheck', '--config']],
            'healthcheck state directory' => [['healthcheck', '--state-dir']],
        ];
    }

    #[DataProvider('emptyValueProvider')]
    public function testOptionsRejectEmptyValues(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CliArgumentParser())->parse($arguments);
    }

    /** @return array<string, array{list<string>}> */
    public static function emptyValueProvider(): array
    {
        return [
            'config argument' => [['--config', '']],
            'state directory argument' => [['--state-dir', '']],
            'config equals form' => [['--config=']],
            'state directory equals form' => [['--state-dir=']],
        ];
    }

    #[DataProvider('optionValueBoundaryProvider')]
    public function testOptionValuesCannotBeAnotherOption(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CliArgumentParser())->parse($arguments);
    }

    /** @return array<string, array{list<string>}> */
    public static function optionValueBoundaryProvider(): array
    {
        return [
            'config option value' => [['--config', '--state-dir']],
            'state directory option value' => [['--state-dir', '--config']],
            'config equals value' => [['--config=-']],
            'state directory equals value' => [['--state-dir=-']],
        ];
    }

    /** @return array<string, array{string}> */
    public static function invalidProvider(): array
    {
        return [
            'unknown' => ['--unknown'],
            'duplicate config' => ['--config'],
            'misplaced positional' => ['task'],
        ];
    }

    private function parserResult(): object
    {
        return (new CliArgumentParser())->parse(['--config', '/tmp/c.php', '--state-dir', '/tmp/state']);
    }
}
