<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Yiisoft\Scheduler\Config\TimeoutPolicy;
use Yiisoft\Scheduler\Integration\Async\{ExecutionBinding, ExecutionIdentity, ExecutionOccurrenceCandidate, ExecutionOccurrenceKey};

use const INF;
use const NAN;

final class ExecutionBindingTest extends TestCase
{
    public function testBindingUsesImmutableIdentityAndTimeoutValueObjects(): void
    {
        $type = new ReflectionClass(ExecutionBinding::class);
        self::assertSame(ExecutionIdentity::class, $type->getProperty('correlation')->getType()->getName());
        self::assertSame(TimeoutPolicy::class, $type->getProperty('timeout')->getType()->getName());
        self::assertTrue($type->getProperty('timeout')->getType()->allowsNull());
        self::assertTrue($type->getProperty('correlation')->isReadOnly());
        self::assertTrue($type->getProperty('timeout')->isReadOnly());
    }

    public function testIdentityIsImmutableAndExposesStableFields(): void
    {
        $identity = $this->identity();
        $type = new ReflectionClass($identity);

        self::assertSame(
            ['taskKey', 'occurrenceId', 'attemptId', 'runId', 'executionId'],
            array_map(static fn($property): string => $property->getName(), $type->getProperties()),
        );
        foreach ($type->getProperties() as $property) {
            self::assertTrue($property->isPublic());
            self::assertTrue($property->isReadOnly());
        }
        self::assertSame('task', $identity->taskKey);
        self::assertSame('execution', $identity->executionId);
    }

    /** @dataProvider invalidIdentityProvider */
    public function testIdentityRejectsInvalidStableIdentifiers(string $field, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExecutionIdentity(
            taskKey: $field === 'taskKey' ? $value : 'task',
            occurrenceId: $field === 'occurrenceId' ? $value : 'occurrence',
            attemptId: $field === 'attemptId' ? $value : 'attempt',
            runId: $field === 'runId' ? $value : 'run',
            executionId: $field === 'executionId' ? $value : 'execution',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidIdentityProvider(): iterable
    {
        $fields = ['taskKey', 'occurrenceId', 'attemptId', 'runId', 'executionId'];
        $invalidValues = [
            'empty' => '',
            'leading digit' => '1identifier',
            'punctuation' => 'identifier/value',
            'oversized' => str_repeat('i', 129),
        ];

        foreach ($fields as $field) {
            foreach ($invalidValues as $boundary => $value) {
                yield "$field $boundary" => [$field, $value];
            }
        }
    }

    /** @dataProvider invalidTimeoutProvider */
    public function testTimeoutPolicyRejectsInvalidValues(?float $soft, ?float $hard): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TimeoutPolicy($soft, $hard);
    }

    /** @return iterable<string, array{?float, ?float}> */
    public static function invalidTimeoutProvider(): iterable
    {
        yield 'zero soft' => [0.0, null];
        yield 'negative hard' => [null, -1.0];
        yield 'hard before soft' => [2.0, 1.0];
        yield 'infinite' => [INF, null];
        yield 'not a number' => [NAN, null];
    }

    public function testAcceptsDigitLeadingRawConfigurationFingerprint(): void
    {
        $configurationFingerprint = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

        $identity = new ExecutionIdentity('task', 'occurrence', 'attempt', 'run', 'execution');
        $timeout = new TimeoutPolicy(1.25, 2.5);
        $binding = new ExecutionBinding(
            'task',
            'occurrence',
            1,
            $identity,
            configurationFingerprint: $configurationFingerprint,
            payload: $this->policy(),
            runId: 'run',
            mode: 'async',
            timeout: $timeout,
        );
        $candidate = new ExecutionOccurrenceCandidate(new ExecutionOccurrenceKey('task', 'occurrence'), $binding, 'cursor-1');

        self::assertSame($configurationFingerprint, $binding->configurationFingerprint);
        self::assertSame($identity, $binding->correlation);
        self::assertSame('async', $binding->mode);
        self::assertSame($timeout, $binding->timeout);
        self::assertIsArray($binding->payload);
        self::assertSame('async', $candidate->taskMappingIdentity()['mode']);
        self::assertSame(['soft' => 1.25, 'hard' => 2.5], $candidate->taskMappingIdentity()['timeout']);
        self::assertArrayNotHasKey('profile', $candidate->taskMappingIdentity());
    }

    /** @dataProvider invalidConfigurationFingerprintProvider */
    public function testRejectsMalformedOrOutOfBoundsConfigurationFingerprint(string $fingerprint): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExecutionBinding(
            'task',
            'occurrence',
            1,
            $this->identity(),
            configurationFingerprint: $fingerprint,
            payload: $this->policy(),
            runId: 'run',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidConfigurationFingerprintProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'wrong length hash' => [str_repeat('1', 63)];
        yield 'digit-leading non-hexadecimal hash' => ['1' . str_repeat('g', 63)];
        yield 'non-portable 64-character vector' => ['a' . str_repeat('!', 63)];
        yield 'punctuation' => ['hash/value'];
    }

    public function testRejectsOutOfBoundsMappingIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExecutionBinding(
            str_repeat('a', 129),
            'occurrence',
            1,
            new ExecutionIdentity(str_repeat('a', 129), 'occurrence', 'attempt', 'run', 'execution'),
            configurationFingerprint: str_repeat('a', 64),
            payload: $this->policy(),
            runId: 'run',
        );
    }

    private function policy(): array
    {
        return ['format' => 4, 'source' => [null, null], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => null, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]];
    }

    private function identity(): ExecutionIdentity
    {
        return new ExecutionIdentity('task', 'occurrence', 'attempt', 'run', 'execution');
    }
}
