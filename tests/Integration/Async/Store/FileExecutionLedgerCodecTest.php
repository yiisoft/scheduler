<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Store;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\{ExecutionDispatchReservation, ExecutionFence, ExecutionIdentity, ExecutionLaunchObservation, ExecutionLedgerRecord, ExecutionLedgerState, ExecutionOccurrenceKey, ExecutionOutcome, ExecutionProcessClosure, ExecutionRecoveryFence};
use Yiisoft\Scheduler\Integration\Async\Store\FileExecutionLedgerCodec;
use PHPUnit\Framework\Attributes\DataProvider;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

final class FileExecutionLedgerCodecTest extends TestCase
{
    public function testEncodeWritesLatestLedgerSnapshotVersion(): void
    {
        $value = json_decode((new FileExecutionLedgerCodec())->encode(self::record()), true, 64, JSON_THROW_ON_ERROR);

        self::assertSame(6, $value['version']);
    }

    public function testCompleteRecordRoundTripPreservesEveryNestedFieldAndFloats(): void
    {
        $record = self::record();
        $json = (new FileExecutionLedgerCodec())->encode($record);

        self::assertStringContainsString('1.0', $json);
        $decoded = (new FileExecutionLedgerCodec())->decode($json);
        self::assertEquals($record, $decoded);
        self::assertSame(1.0, $decoded->metadata['ratio']);
        self::assertSame(1.0, $decoded->taskSnapshot['task']['metadata']['ratio']);
        self::assertSame(1.0, $decoded->outcome?->metadata['ratio']);
        self::assertTrue($decoded->launchObservation?->closure?->isConfirmed());
        self::assertSame(0, $decoded->launchObservation?->closure?->exitCode);
        self::assertSame('dispatcher', $decoded->dispatchReservation?->dispatcherId);
        self::assertSame('recovery', $decoded->recoveryFence?->token);
        self::assertSame(30, $decoded->claimLeaseDeadline);
    }

    public function testMaximumScalarDepthRoundTripsThroughAllSnapshotFields(): void
    {
        $outcome = new ExecutionOutcome(ExecutionOutcome::SUCCEEDED, 'done', self::deepTree(32));
        $record = new ExecutionLedgerRecord(
            new ExecutionOccurrenceKey('task', 'occurrence'),
            new ExecutionIdentity('task', 'occurrence', 'attempt', 'run', 'execution'),
            1,
            new ExecutionFence(1, 'fence-token'),
            ExecutionLedgerState::SUCCEEDED,
            'launch-intent',
            'manifest',
            ['format' => 4, 'source' => [null, null], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [1.0, 2.0], 'metadata' => ['deep' => self::deepTree(20)], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]],
            10,
            20,
            $outcome,
            self::deepTree(32),
            null,
            new ExecutionLaunchObservation(123, null, 'complete', 16, ExecutionProcessClosure::confirmed(0, 'closed')),
        );

        $decoded = (new FileExecutionLedgerCodec())->decode((new FileExecutionLedgerCodec())->encode($record));
        self::assertEquals($record, $decoded);
    }

    #[DataProvider('invalidObjectShapeProvider')]
    public function testUnknownOrMissingTopLevelAndNestedObjectKeysAreRejected(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FileExecutionLedgerCodec())->decode($json);
    }

    public static function invalidObjectShapeProvider(): array
    {
        return [
            'top-level unknown' => [self::json(['extra' => true])],
            'top-level missing' => [self::json([], ['metadata'])],
            'occurrence unknown' => [self::json([], [], ['extra' => true], 'occurrence')],
            'occurrence missing' => [self::json([], [], [], 'occurrence', ['occurrenceId'])],
            'correlation unknown' => [self::json([], [], ['extra' => true], 'correlation')],
            'fence missing' => [self::json([], [], [], 'fence', ['token'])],
            'outcome unknown' => [self::json([], [], ['extra' => true], 'outcome')],
            'reservation missing' => [self::json([], [], [], 'dispatchReservation', ['deadline'])],
            'observation unknown' => [self::json([], [], ['extra' => true], 'launchObservation')],
        ];
    }

    #[DataProvider('numericObjectShapeProvider')]
    public function testNumericKeyArraysMasqueradingAsObjectsAreRejected(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FileExecutionLedgerCodec())->decode($json);
    }

    public static function numericObjectShapeProvider(): array
    {
        return [
            'occurrence tuple' => [self::json([], [], ['task', 'occurrence'], 'occurrence')],
            'fence tuple' => [self::json([], [], [1, 'token'], 'fence')],
            'outcome tuple' => [self::json([], [], ['succeeded', null, []], 'outcome')],
            'reservation tuple' => [self::json([], [], ['dispatcher', 11, 15], 'dispatchReservation')],
            'observation tuple' => [self::json([], [], [123, null, 'complete', 16, null], 'launchObservation')],
        ];
    }

    #[DataProvider('invalidFactsProvider')]
    public function testWrongIdentityStateOutcomeAndBoundedScalarsAreRejected(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FileExecutionLedgerCodec())->decode($json);
    }

    public static function invalidFactsProvider(): array
    {
        return [
            'wrong occurrence identity' => [self::json(['occurrence' => ['taskKey' => 'other', 'occurrenceId' => 'occurrence']])],
            'non-terminal without outcome' => [self::json(['state' => ExecutionLedgerState::RUNNING, 'outcome' => self::record()->outcome])],
            'terminal with no outcome' => [self::json(['state' => ExecutionLedgerState::SUCCEEDED, 'outcome' => null])],
            'outcome status differs from state' => [self::json(['state' => ExecutionLedgerState::SUCCEEDED, 'outcome' => ['status' => ExecutionOutcome::FAILED, 'reason' => 'done', 'metadata' => []]])],
            'attempt below one' => [self::json(['attempt' => 0])],
            'negative timestamp' => [self::json(['createdAt' => -1])],
            'empty launch intent' => [self::json(['launchIntent' => ''])],
            'oversized configuration fingerprint' => [self::json(['configurationFingerprint' => str_repeat('m', 4097)])],
            'oversized identity' => [self::json(['occurrence' => ['taskKey' => 'task', 'occurrenceId' => 'o' . str_repeat('x', 127)]])],
            'invalid reservation deadline' => [self::json(['dispatchReservation' => ['dispatcherId' => 'dispatcher', 'reservedAt' => 11, 'deadline' => 10]])],
            'invalid observation pid' => [self::json(['launchObservation' => ['pid' => 0, 'error' => null, 'classification' => 'complete', 'observedAt' => 16, 'closure' => null]])],
            'oversized observation classification' => [self::json(['launchObservation' => ['pid' => 123, 'error' => null, 'classification' => str_repeat('x', 129), 'observedAt' => 16, 'closure' => null]])],
            'empty outcome reason' => [self::json(['outcome' => ['status' => ExecutionOutcome::SUCCEEDED, 'reason' => '', 'metadata' => []]])],
        ];
    }

    #[DataProvider('unsupportedVersionProvider')]
    public function testOnlyLatestLedgerSnapshotVersionIsAccepted(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FileExecutionLedgerCodec())->decode($json);
    }

    public static function unsupportedVersionProvider(): array
    {
        $current = json_decode((new FileExecutionLedgerCodec())->encode(self::record()), true, 64, JSON_THROW_ON_ERROR);
        $v1 = $current;
        $v1['version'] = 1;
        $v1['launchObservation']['completion'] = $current['outcome'];
        unset($v1['launchObservation']['closure']);
        $v2 = $current;
        $v2['version'] = 2;
        $v3 = $current;
        $v3['version'] = 3;
        $v4 = $current;
        $v4['version'] = 4;
        $v5 = $current;
        $v5['version'] = 5;
        $unknown = $current;
        $unknown['version'] = 99;

        return [
            'version 1 legacy observation' => [json_encode($v1, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)],
            'version 2' => [json_encode($v2, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)],
            'version 3' => [json_encode($v3, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)],
            'version 4 legacy snapshot' => [json_encode($v4, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)],
            'version 5 legacy snapshot' => [json_encode($v5, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)],
            'unknown version' => [json_encode($unknown, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)],
        ];
    }

    public function testOldManifestKeyIsRejected(): void
    {
        $value = json_decode((new FileExecutionLedgerCodec())->encode(self::record()), true, 64, JSON_THROW_ON_ERROR);
        $value['manifest'] = $value['configurationFingerprint'];
        unset($value['configurationFingerprint']);
        $this->expectException(InvalidArgumentException::class);
        (new FileExecutionLedgerCodec())->decode(json_encode($value, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('malformedPolicyProvider')]
    public function testMalformedFormatFourPoliciesAreRejectedBeforeDecode(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FileExecutionLedgerCodec())->decode($json);
    }

    public static function malformedPolicyProvider(): array
    {
        $codec = new FileExecutionLedgerCodec();
        $base = json_decode($codec->encode(self::record()), true, 64, JSON_THROW_ON_ERROR);

        $formatFour = $base;
        $formatFour['taskSnapshot']['format'] = 3;
        $objectId = $base;
        $objectId['taskSnapshot']['task']['id'] = ['task'];
        $integerTimeout = $base;
        $integerTimeout['taskSnapshot']['task']['timeout'] = [1, 2.0];
        $reversedTimeout = $base;
        $reversedTimeout['taskSnapshot']['task']['timeout'] = [2.0, 1.0];
        $invalidTrigger = $base;
        $invalidTrigger['taskSnapshot']['task']['trigger'][0] = ['periodic'];

        return array_map(
            static fn(array $value): array => [json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)],
            [$formatFour, $objectId, $integerTimeout, $reversedTimeout, $invalidTrigger],
        );
    }

    public function testEncodeRejectsMalformedPolicyUsingSharedContract(): void
    {
        $record = self::record();
        $invalid = new ExecutionLedgerRecord(
            $record->occurrence,
            $record->correlation,
            $record->attempt,
            $record->fence,
            $record->state,
            $record->launchIntent,
            $record->configurationFingerprint,
            [...$record->taskSnapshot, 'task' => [...$record->taskSnapshot['task'], 'id' => ['task']]],
            $record->createdAt,
            $record->updatedAt,
            $record->outcome,
            $record->metadata,
            $record->dispatchReservation,
            $record->launchObservation,
            $record->recoveryFence,
            $record->revokedAt,
            $record->claimLeaseDeadline,
        );

        $this->expectException(InvalidArgumentException::class);
        (new FileExecutionLedgerCodec())->encode($invalid);
    }

    public function testMalformedJsonIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FileExecutionLedgerCodec())->decode('{not-json');
    }

    public function testOversizedSnapshotIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new FileExecutionLedgerCodec())->decode(str_repeat('x', FileExecutionLedgerCodec::MAX_BYTES + 1));
    }

    private static function deepTree(int $depth): array
    {
        $value = 'leaf';
        for ($i = 0; $i < $depth; ++$i) {
            $value = ['value' => $value];
        }
        return $value;
    }

    private static function json(array $top = [], array $missing = [], mixed $replacement = null, ?string $field = null, array $nestedMissing = []): string
    {
        $value = json_decode((new FileExecutionLedgerCodec())->encode(self::record()), true, 32, JSON_THROW_ON_ERROR);
        foreach ($missing as $key) {
            unset($value[$key]);
        }
        if ($field !== null) {
            if ($replacement !== null) {
                $value[$field] = $replacement;
            } else {
                foreach ($nestedMissing as $key) {
                    unset($value[$field][$key]);
                }
            }
        }
        foreach ($top as $key => $replacementValue) {
            $value[$key] = $replacementValue;
        }
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function record(): ExecutionLedgerRecord
    {
        $outcome = new ExecutionOutcome(ExecutionOutcome::SUCCEEDED, 'done', ['ratio' => 1.0]);
        return new ExecutionLedgerRecord(
            new ExecutionOccurrenceKey('task', 'occurrence'),
            new ExecutionIdentity('task', 'occurrence', 'attempt', 'run', 'execution'),
            1,
            new ExecutionFence(1, 'fence-token'),
            ExecutionLedgerState::SUCCEEDED,
            'launch-intent',
            'manifest',
            ['format' => 4, 'source' => [null, null], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [1.0, 2.0], 'metadata' => ['ratio' => 1.0, 'nested' => ['value' => 2.5]], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]],
            10,
            20,
            $outcome,
            ['ratio' => 1.0],
            new ExecutionDispatchReservation('dispatcher', 11, 15),
            new ExecutionLaunchObservation(123, null, 'complete', 16, ExecutionProcessClosure::confirmed(0, 'closed')),
            new ExecutionRecoveryFence('recovery'),
            null,
            30,
        );
    }
}
