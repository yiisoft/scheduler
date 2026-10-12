<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Yiisoft\Scheduler\Config\{ConfigurationPreparationResult, ConfigurationSourceInterface, ConfigurationSourcePin, ConfigurationVersionGuard};
use TypeError;
use stdClass;

use function array_shift;
use function str_repeat;

final class ConfigurationSourceTest extends TestCase
{
    public function testPinReturnsSourceValueObject(): void
    {
        $pin = (new ConfigurationVersionGuard())->pin(new MutableSource('revision'));

        self::assertInstanceOf(ConfigurationSourcePin::class, $pin);
        self::assertSame('test-source', $pin->sourceId);
        self::assertSame('revision', $pin->revision);
    }

    public function testGuardExposesTypedPreparationBoundary(): void
    {
        $pinMethod = new ReflectionMethod(ConfigurationVersionGuard::class, 'pin');
        self::assertSame(ConfigurationSourceInterface::class, $pinMethod->getParameters()[0]->getType()?->getName());
        self::assertSame(ConfigurationSourcePin::class, $pinMethod->getReturnType()?->getName());

        $executeMethod = new ReflectionMethod(ConfigurationVersionGuard::class, 'execute');
        self::assertSame(ConfigurationSourceInterface::class, $executeMethod->getParameters()[0]->getType()?->getName());
        self::assertSame('callable', $executeMethod->getParameters()[1]->getType()?->getName());
        self::assertSame(ConfigurationPreparationResult::class, $executeMethod->getReturnType()?->getName());
    }

    /** @dataProvider invalidPinProvider */
    public function testPinRejectsInvalidSourceOrRevision(string $sourceId, string $revision): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConfigurationSourcePin($sourceId, $revision);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPinProvider(): iterable
    {
        yield 'empty source' => ['', 'revision'];
        yield 'empty revision' => ['source', ''];
        yield 'oversized source' => [str_repeat('s', 4097), 'revision'];
        yield 'oversized revision' => ['source', str_repeat('r', 129)];
    }

    public function testPinFieldsProvideStableComparison(): void
    {
        $first = new ConfigurationSourcePin('source', 'revision');
        $same = new ConfigurationSourcePin('source', 'revision');
        $different = new ConfigurationSourcePin('source', 'other-revision');

        self::assertEquals($first, $same);
        self::assertNotEquals($first, $different);
    }

    public function testPinAndPreparationRejectInvalidTypedInputs(): void
    {
        $guard = new ConfigurationVersionGuard();

        try {
            $guard->pin(new stdClass());
            self::fail('A non-configuration source must be rejected.');
        } catch (TypeError) {
            self::assertTrue(true);
        }

        try {
            $guard->execute(new MutableSource(), 'not-callable');
            self::fail('A non-callable preparation must be rejected.');
        } catch (TypeError) {
            self::assertTrue(true);
        }
    }

    public function testPreparationResultPreservesDynamicPreparedOutputWithoutWrappingIt(): void
    {
        $source = new MutableSource();
        $output = ['commands' => ['shell' => ['metadata' => ['dynamic' => true]]], 'tasks' => []];

        $prepared = (new ConfigurationVersionGuard())->execute($source, static fn(): array => $output);

        self::assertInstanceOf(ConfigurationPreparationResult::class, $prepared);
        self::assertSame($output, $prepared->result);
        self::assertIsArray($prepared->result);
        self::assertSame(['dynamic' => true], $prepared->result['commands']['shell']['metadata']);
    }

    public function testExecutionReturnsTheRevisionVerifiedByItsOwnFence(): void
    {
        $source = new MutableSource('new-revision');
        // A pin from an earlier read must not be reused by compilation.
        $source->revisionValues = ['stale-revision', 'stale-revision'];
        (new ConfigurationVersionGuard())->pin($source);

        $prepared = (new ConfigurationVersionGuard())->execute($source, static fn(): string => 'prepared');

        self::assertInstanceOf(ConfigurationPreparationResult::class, $prepared);
        self::assertSame('prepared', $prepared->result);
        self::assertInstanceOf(ConfigurationSourcePin::class, $prepared->pin);
        self::assertSame('test-source', $prepared->pin->sourceId);
        self::assertSame('new-revision', $prepared->pin->revision);
    }

    public function testChangedSourceFailsClosed(): void
    {
        $source = new MutableSource();
        $this->expectException(InvalidArgumentException::class);
        (new ConfigurationVersionGuard())->execute($source, static function () use ($source): void {
            $source->value = 'changed';
        });
    }

    public function testEmptyCustomRevisionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('empty or too long');

        (new ConfigurationVersionGuard())->pin(new MutableSource(''));
    }

    public function testOversizedCustomRevisionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('empty or too long');

        (new ConfigurationVersionGuard())->pin(new MutableSource(str_repeat('x', 129)));
    }

    public function testUnstableRevisionIsRejected(): void
    {
        $source = new MutableSource('revision');
        $source->revisionValues = ['revision', 'changed'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('revision is unstable');

        (new ConfigurationVersionGuard())->pin($source);
    }

    public function testSourceReplacementDuringPreparationIsRejected(): void
    {
        $source = new MutableSource();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('identity changed');

        (new ConfigurationVersionGuard())->execute($source, static function () use ($source): void {
            $source->identity = 'replaced-source';
        });
    }

    public function testSourceDeletionDuringPreparationIsRejected(): void
    {
        $source = new MutableSource();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('empty or too long');

        (new ConfigurationVersionGuard())->execute($source, static function () use ($source): void {
            $source->value = '';
        });
    }
}

final class MutableSource implements ConfigurationSourceInterface
{
    /** @var list<string> */
    public array $revisionValues = [];
    public string $identity = 'test-source';

    public function __construct(public string $value = 'revision') {}

    public function sourceId(): string
    {
        return $this->identity;
    }

    public function revision(): string
    {
        return $this->revisionValues === [] ? $this->value : (string) array_shift($this->revisionValues);
    }
}
