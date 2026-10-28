<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Planner;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\TimeoutPolicy;
use Yiisoft\Scheduler\Integration\Async\ExecutionBinding;
use Yiisoft\Scheduler\Integration\Async\ExecutionFence;
use Yiisoft\Scheduler\Integration\Async\ExecutionIdentity;
use Yiisoft\Scheduler\Integration\Async\ExecutionLedgerQueryInterface;
use Yiisoft\Scheduler\Integration\Async\ExecutionLedgerRecord;
use Yiisoft\Scheduler\Integration\Async\ExecutionLedgerState;
use Yiisoft\Scheduler\Integration\Async\ExecutionRecoveryFence;
use Yiisoft\Scheduler\Integration\Async\ExecutionOccurrenceCandidate;
use Yiisoft\Scheduler\Integration\Async\ExecutionOccurrenceKey;
use Yiisoft\Scheduler\Planner\ExecutionAdmissionMaterialFactory;
use Yiisoft\Scheduler\Tests\Support\TestClock;
use InvalidArgumentException;

use const PHP_INT_MAX;

final class ExecutionAdmissionMaterialFactoryTest extends TestCase
{
    public function testUnenforcedTimeoutUsesUnboundedReservationDeadline(): void
    {
        $ledger = $this->createMock(ExecutionLedgerQueryInterface::class);
        $ledger->method('observe')->willReturn(null);

        $material = (new ExecutionAdmissionMaterialFactory(
            $ledger,
            new TestClock(new DateTimeImmutable('@100')),
            'dispatcher-1',
            'ledger://one',
            'runtime://one',
            static fn(int $generation): string => 'new-fence-' . $generation,
        ))->forCandidate($this->candidate(null), null);

        self::assertSame(PHP_INT_MAX, $material->reservation->deadline);
    }

    public function testEffectiveUnenforcedTimeoutUsesUnboundedReservationDeadline(): void
    {
        $ledger = $this->createMock(ExecutionLedgerQueryInterface::class);
        $ledger->method('observe')->willReturn(null);

        $material = (new ExecutionAdmissionMaterialFactory(
            $ledger,
            new TestClock(new DateTimeImmutable('@100')),
            'dispatcher-1',
            'ledger://one',
            'runtime://one',
            static fn(int $generation): string => 'new-fence-' . $generation,
        ))->forCandidate($this->candidate([null, null]), new TimeoutPolicy(null, null));

        self::assertSame(PHP_INT_MAX, $material->reservation->deadline);
    }

    public function testValidExistingRecordRemainsUnchanged(): void
    {
        $candidate = $this->candidate([3.0, 5.0], 'source-1', 'durable-revision', 'durable-manifest');
        $existing = $this->durableRecord($candidate->mapping->payload, $candidate);
        $ledger = $this->createMock(ExecutionLedgerQueryInterface::class);
        $ledger->method('observe')->willReturn($existing);

        $material = (new ExecutionAdmissionMaterialFactory(
            $ledger,
            new TestClock(new DateTimeImmutable('@100')),
            'dispatcher-1',
            'ledger://one',
            'runtime://one',
            static fn(int $generation): string => 'new-fence-' . $generation,
        ))->forCandidate($candidate, null);

        self::assertSame($existing, $material->record);
        self::assertSame(105, $material->reservation->deadline);
        self::assertNotSame('', $material->capability->taskVersion);
        self::assertSame('durable-manifest', $material->record->configurationFingerprint);
        self::assertSame('durable-revision', $material->record->metadata['sourceRevision']);
    }

    public function testExistingRecordRejectsAforgedCandidateIdentity(): void
    {
        $existing = $this->durableRecord($this->policy(), $this->candidate([3.0, 5.0], 'source-1', 'source-revision', 'durable-manifest'));
        $ledger = $this->createMock(ExecutionLedgerQueryInterface::class);
        $ledger->method('observe')->willReturn($existing);

        $this->expectException(InvalidArgumentException::class);
        (new ExecutionAdmissionMaterialFactory(
            $ledger,
            new TestClock(new DateTimeImmutable('@100')),
            'dispatcher-1',
            'ledger://one',
            'runtime://one',
            static fn(int $generation): string => 'new-fence-' . $generation,
        ))->forCandidate($this->candidate([9.0, 10.0], 'source-1', 'different-revision', 'durable-manifest'), null);
    }

    public function testFirstRunConvertsPortableCorrelationAtLedgerBoundary(): void
    {
        $ledger = $this->createMock(ExecutionLedgerQueryInterface::class);
        $ledger->method('observe')->willReturn(null);
        $candidate = new ExecutionOccurrenceCandidate(
            new ExecutionOccurrenceKey('task', 'occurrence'),
            new ExecutionBinding(
                'task',
                'occurrence',
                10,
                new ExecutionIdentity('task', 'occurrence', 'attempt-1', 'run-1', 'execution-1'),
                'manifest',
                $this->policy('source-1', 'source-revision'),
                ['sourceRevision' => 'source-revision'],
                'source-1',
                'run-1',
                new TimeoutPolicy(3.0, 5.0),
                false,
                'async',
            ),
            'cursor-1',
        );

        $material = (new ExecutionAdmissionMaterialFactory(
            $ledger,
            new TestClock(new DateTimeImmutable('@100')),
            'dispatcher-1',
            'ledger://one',
            'runtime://one',
            static fn(int $generation): string => 'fence-' . $generation,
        ))->forCandidate($candidate, new TimeoutPolicy(3.0, 5.0));

        self::assertSame('run-1', $material->record->correlation->runId);
        self::assertSame('execution-1', $material->record->correlation->executionId);
        self::assertSame($candidate->mapping->correlation, $material->record->correlation);
        self::assertSame('async', $material->record->metadata['taskMappingIdentity']['mode']);
        self::assertSame(['soft' => 3.0, 'hard' => 5.0], $material->record->metadata['taskMappingIdentity']['timeout']);
        self::assertInstanceOf(ExecutionFence::class, $material->record->fence);
        self::assertSame('source-revision', $material->record->metadata['sourceRevision']);
        self::assertSame('source-revision', $material->capability->sourceRevision);
    }

    /** @param array<string, mixed> $taskSnapshot */
    private function durableRecord(array $taskSnapshot, ?ExecutionOccurrenceCandidate $candidate = null): ExecutionLedgerRecord
    {
        return new ExecutionLedgerRecord(
            new ExecutionOccurrenceKey('task', 'occurrence'),
            new ExecutionIdentity('task', 'occurrence', 'attempt-1', 'run-1', 'execution-1'),
            1,
            new ExecutionFence(3, 'durable-fence'),
            ExecutionLedgerState::LAUNCH_REQUIRED,
            'durable-manifest',
            $taskSnapshot,
            90,
            95,
            metadata: $candidate === null ? ['sourceRevision' => 'durable-revision'] : [
                'occurrenceCursor' => $candidate->cursor,
                'taskMappingIdentity' => $candidate->taskMappingIdentity(),
                'taskMappingContext' => $candidate->mapping->context,
                'sourceId' => $candidate->mapping->locator,
                'sourceRevision' => $taskSnapshot['source'][1],
            ],
            recoveryFence: new ExecutionRecoveryFence('durable-recovery', 3),
            sourceIdentity: $taskSnapshot['source'][0],
            sourceRevision: $taskSnapshot['source'][1],
        );
    }

    private function policy(string $sourceId = 'source-1', string $revision = 'source-revision', ?array $timeout = [3.0, 5.0]): array
    {
        return [
            'schema' => 'dev',
            'source' => [$sourceId, $revision],
            'task' => [
                'id' => 'task', 'mode' => 'async', 'timeout' => $timeout, 'recoveryMaxAge' => 180.0, 'metadata' => [],
                'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60]],
                'overlap' => 'allow', 'missed' => ['skip', null],
            ],
        ];
    }

    private function candidate(?array $timeout = [3.0, 5.0], string $sourceId = 'source-1', string $revision = 'candidate-revision', string $fingerprint = 'candidate-manifest'): ExecutionOccurrenceCandidate
    {
        return new ExecutionOccurrenceCandidate(
            new ExecutionOccurrenceKey('task', 'occurrence'),
            new ExecutionBinding(
                'task',
                'occurrence',
                10,
                new ExecutionIdentity('task', 'occurrence', 'attempt-1', 'run-1', 'execution-1'),
                $fingerprint,
                $this->policy($sourceId, $revision, $timeout),
                ['sourceRevision' => $revision],
                $sourceId,
                'run-1',
                $timeout === null ? null : new TimeoutPolicy($timeout[0], $timeout[1]),
                false,
                'async',
            ),
            'cursor-1',
        );
    }
}
