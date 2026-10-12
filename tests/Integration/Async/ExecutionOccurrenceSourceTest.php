<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Integration\Async;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Schedule\Config\TimeoutPolicy;
use Yiisoft\Schedule\Integration\Async\{
    ExecutionIdentity,
    ExecutionBinding,
    ExecutionAdmission,
    ExecutionFence,
    ExecutionLedgerRecord,
    ExecutionLedgerState,
    ExecutionOccurrenceCandidate,
    ExecutionOccurrenceKey,
    ExecutionOccurrenceSourceInterface,
    OccurrenceSkipReason,
};
use PHPUnit\Framework\Attributes\DataProvider;

final class ExecutionOccurrenceSourceTest extends TestCase
{
    public function testSkipReasonsAreClosedAndExcludeCapacity(): void
    {
        self::assertSame(
            ['overlap', 'downtime', 'range-trim'],
            array_map(static fn(OccurrenceSkipReason $reason): string => $reason->value, OccurrenceSkipReason::cases()),
        );
        self::assertSame(OccurrenceSkipReason::RANGE_TRIM, OccurrenceSkipReason::fromString('range-trim'));
        $this->assertThrows(fn() => OccurrenceSkipReason::fromString('capacity'));
    }

    public function testCandidateIsBoundedConsistentAndRetainsSnapshot(): void
    {
        $mapping = $this->mapping('occurrence-1', 'manifest-v1', 'task-snapshot-v1');
        $candidate = new ExecutionOccurrenceCandidate(new ExecutionOccurrenceKey('schedule', 'task', 'occurrence-1'), $mapping, 'position-1');

        self::assertSame('manifest-v1', $candidate->mapping->configurationFingerprint);
        self::assertSame('task-snapshot-v1', $candidate->mapping->payload['task']['metadata']['snapshot']);
        $this->assertThrows(fn() => new ExecutionOccurrenceCandidate(new ExecutionOccurrenceKey('schedule', 'other', 'occurrence-1'), $mapping, 'position-1'));
        $this->assertThrows(fn() => new ExecutionOccurrenceCandidate($candidate->occurrence, $mapping, str_repeat('x', 2049)));
    }

    public function testOnlyCommittedNewOrExistingAdmissionAdvancesExactlyOnce(): void
    {
        $first = new ExecutionOccurrenceCandidate(new ExecutionOccurrenceKey('schedule', 'task', 'occurrence-1'), $this->mapping('occurrence-1', 'v1', 'one'), 'p1');
        $second = new ExecutionOccurrenceCandidate(new ExecutionOccurrenceKey('schedule', 'task', 'occurrence-2'), $this->mapping('occurrence-2', 'v2', 'two'), 'p2');
        $source = new FakeOccurrenceSource([$first, $second]);

        self::assertSame($first, $source->next());
        self::assertSame($first, $source->next()); // rejected/uncommitted work leaves it retryable
        $admission = $this->admission($first);
        $source->acknowledge($first, $admission);
        self::assertSame($second, $source->next());
        $this->assertThrows(fn() => $source->acknowledge($first, $admission));
        self::assertSame($second, $source->next());

        // An idempotent existing durable admission is still a committed acknowledgement.
        $source->acknowledge($second, new ExecutionAdmission($this->record($second), false));
        self::assertNull($source->next());
    }

    public function testMismatchedAdmissionDoesNotAdvance(): void
    {
        $first = new ExecutionOccurrenceCandidate(new ExecutionOccurrenceKey('schedule', 'task', 'occurrence-1'), $this->mapping('occurrence-1', 'v1', 'one'), 'p1');
        $other = new ExecutionOccurrenceCandidate(new ExecutionOccurrenceKey('schedule', 'task', 'occurrence-2'), $this->mapping('occurrence-2', 'v1', 'two'), 'p2');
        $source = new FakeOccurrenceSource([$first]);

        $this->assertThrows(fn() => $source->acknowledge($first, new ExecutionAdmission($this->record($other), true)));
        self::assertSame($first, $source->next());
    }

    public function testSkipConsumesExactlyCurrentCandidateAndLeavesCapacityDecisionRetryable(): void
    {
        $first = new ExecutionOccurrenceCandidate(new ExecutionOccurrenceKey('schedule', 'task', 'occurrence-1'), $this->mapping('occurrence-1', 'v1', 'one'), 'p1');
        $second = new ExecutionOccurrenceCandidate(new ExecutionOccurrenceKey('schedule', 'task', 'occurrence-2'), $this->mapping('occurrence-2', 'v2', 'two'), 'p2');
        $source = new FakeOccurrenceSource([$first, $second]);

        self::assertSame($first, $source->next());
        foreach (['capacity', 'duplicate', '', "reason\n", str_repeat('x', 2049)] as $reason) {
            $this->assertThrows(fn() => $source->skip($first, OccurrenceSkipReason::fromString($reason)));
            self::assertSame($first, $source->next());
        }
        $source->skip($first, OccurrenceSkipReason::OVERLAP);
        self::assertSame($second, $source->next());

        $this->assertThrows(fn() => $source->skip($first, OccurrenceSkipReason::OVERLAP));
        self::assertSame($second, $source->next());
    }

    #[DataProvider('candidateBindingMismatches')]
    public function testSameOccurrenceCandidateBindingMismatchLeavesCursorRetryable(callable $candidate): void
    {
        $current = new ExecutionOccurrenceCandidate(new ExecutionOccurrenceKey('schedule', 'task', 'occurrence-1'), $this->mapping('occurrence-1', 'manifest-v1', 'snapshot-v1'), 'cursor-1');
        $acknowledged = $candidate($this, $current);
        $source = new FakeOccurrenceSource([$current]);

        $this->assertThrows(fn() => $source->acknowledge($acknowledged, new ExecutionAdmission($this->record($acknowledged), true)));
        self::assertSame($current, $source->next());
    }

    /** @return array<string, array{callable}> */
    public static function candidateBindingMismatches(): array
    {
        return [
            'cursor' => [static fn(self $test, ExecutionOccurrenceCandidate $candidate) => new ExecutionOccurrenceCandidate($candidate->occurrence, $candidate->mapping, 'cursor-2')],
            'correlation' => [static fn(self $test, ExecutionOccurrenceCandidate $candidate) => new ExecutionOccurrenceCandidate($candidate->occurrence, $test->mappingWithCorrelation($candidate, 'attempt-2'), $candidate->cursor)],
            'configuration fingerprint' => [static fn(self $test, ExecutionOccurrenceCandidate $candidate) => new ExecutionOccurrenceCandidate($candidate->occurrence, $test->mapping($candidate->occurrence->occurrenceId, 'manifest-v2', 'snapshot-v1'), $candidate->cursor)],
            'task policy' => [static fn(self $test, ExecutionOccurrenceCandidate $candidate) => new ExecutionOccurrenceCandidate($candidate->occurrence, $test->mapping($candidate->occurrence->occurrenceId, 'manifest-v1', 'snapshot-v2'), $candidate->cursor)],
            'context' => [static fn(self $test, ExecutionOccurrenceCandidate $candidate) => new ExecutionOccurrenceCandidate($candidate->occurrence, $test->mappingWithContext($candidate, ['tenant' => 'other']), $candidate->cursor)],
            'task mapping identity' => [static fn(self $test, ExecutionOccurrenceCandidate $candidate) => new ExecutionOccurrenceCandidate($candidate->occurrence, $test->mappingWithPolicy($candidate, 'snapshot-other'), $candidate->cursor)],
        ];
    }

    #[DataProvider('admissionBindingMismatches')]
    public function testEveryAdmissionBindingMismatchLeavesCursorRetryable(callable $admission): void
    {
        $candidate = new ExecutionOccurrenceCandidate(new ExecutionOccurrenceKey('schedule', 'task', 'occurrence-1'), $this->mapping('occurrence-1', 'manifest-v1', 'snapshot-v1'), 'cursor-1');
        $source = new FakeOccurrenceSource([$candidate]);

        $this->assertThrows(fn() => $source->acknowledge($candidate, $admission($this, $candidate)));
        self::assertSame($candidate, $source->next());
    }

    /** @return array<string, array{callable}> */
    public static function admissionBindingMismatches(): array
    {
        return [
            'cursor' => [static fn(self $test, ExecutionOccurrenceCandidate $candidate) => new ExecutionAdmission($test->record($candidate, cursor: 'cursor-2'), true)],
            'correlation' => [static fn(self $test, ExecutionOccurrenceCandidate $candidate) => new ExecutionAdmission($test->record($candidate, correlation: new ExecutionIdentity('schedule', 'task', 'occurrence-1', 'attempt-2', 'run-1', 'execution-1')), true)],
            'configuration fingerprint' => [static fn(self $test, ExecutionOccurrenceCandidate $candidate) => new ExecutionAdmission($test->record($candidate, configurationFingerprint: 'manifest-v2'), true)],
            'task policy' => [static fn(self $test, ExecutionOccurrenceCandidate $candidate) => new ExecutionAdmission($test->record($candidate, snapshot: $test->policy('snapshot-v2')), true)],
            'context' => [static fn(self $test, ExecutionOccurrenceCandidate $candidate) => new ExecutionAdmission($test->record($candidate, context: ['tenant' => 'other']), true)],
            'task mapping identity' => [static fn(self $test, ExecutionOccurrenceCandidate $candidate) => new ExecutionAdmission($test->record($candidate, mappingIdentity: ['mode' => 'other']), true)],
        ];
    }

    private function mapping(string $occurrence, string $configurationFingerprint, string $snapshot): ExecutionBinding
    {
        return new ExecutionBinding(
            'schedule',
            'task',
            $occurrence,
            100,
            new ExecutionIdentity('schedule', 'task', $occurrence, 'attempt-1', 'run-1', 'execution-1'),
            $configurationFingerprint,
            $this->policy($snapshot),
            runId: 'run-1',
            timeout: new TimeoutPolicy(100.0),
            mode: 'async',
        );
    }

    private function mappingWithCorrelation(ExecutionOccurrenceCandidate $candidate, string $attempt): ExecutionBinding
    {
        return new ExecutionBinding(
            'schedule',
            'task',
            $candidate->occurrence->occurrenceId,
            100,
            new ExecutionIdentity('schedule', 'task', $candidate->occurrence->occurrenceId, $attempt, 'run-1', 'execution-1'),
            $candidate->mapping->configurationFingerprint,
            $candidate->mapping->payload,
            runId: 'run-1',
            timeout: new TimeoutPolicy(100.0),
            mode: 'async',
        );
    }

    private function mappingWithPolicy(ExecutionOccurrenceCandidate $candidate, string $value): ExecutionBinding
    {
        return new ExecutionBinding(
            'schedule',
            'task',
            $candidate->occurrence->occurrenceId,
            100,
            $candidate->mapping->correlation,
            $candidate->mapping->configurationFingerprint,
            $this->policy($value),
            context: $candidate->mapping->context,
            runId: 'run-1',
            timeout: new TimeoutPolicy(100.0),
            mode: 'async',
        );
    }

    private function mappingWithContext(ExecutionOccurrenceCandidate $candidate, array $context): ExecutionBinding
    {
        return new ExecutionBinding('schedule', 'task', $candidate->occurrence->occurrenceId, 100, $candidate->mapping->correlation, $candidate->mapping->configurationFingerprint, $candidate->mapping->payload, context: $context, runId: 'run-1', timeout: new TimeoutPolicy(100.0), mode: 'async');
    }

    private function policy(string $value = 'snapshot-v1'): array
    {
        return ['format' => 3, 'source' => [null, null], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [100.0, null], 'metadata' => ['snapshot' => $value], 'schedule' => 'schedule', 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]];
    }

    private function record(
        ExecutionOccurrenceCandidate $candidate,
        ?ExecutionIdentity $correlation = null,
        ?string $configurationFingerprint = null,
        ?array $snapshot = null,
        ?string $cursor = null,
        ?array $mappingIdentity = null,
        ?array $context = null,
    ): ExecutionLedgerRecord {
        return new ExecutionLedgerRecord(
            $candidate->occurrence,
            $correlation ?? new ExecutionIdentity(
                $candidate->mapping->correlation->scheduleKey,
                $candidate->mapping->correlation->taskKey,
                $candidate->mapping->correlation->occurrenceId,
                $candidate->mapping->correlation->attemptId,
                $candidate->mapping->correlation->runId,
                $candidate->mapping->correlation->executionId,
            ),
            1,
            new ExecutionFence(1, 'fence'),
            ExecutionLedgerState::LAUNCH_REQUIRED,
            'launch',
            $configurationFingerprint ?? $candidate->mapping->configurationFingerprint,
            $snapshot ?? $candidate->mapping->payload,
            1,
            1,
            metadata: [
                'occurrenceCursor' => $cursor ?? $candidate->cursor,
                'taskMappingIdentity' => $mappingIdentity ?? $candidate->taskMappingIdentity(),
                'taskMappingContext' => $context ?? $candidate->mapping->context,
            ],
        );
    }

    private function admission(ExecutionOccurrenceCandidate $candidate): ExecutionAdmission
    {
        return new ExecutionAdmission($this->record($candidate), true);
    }

    private function assertThrows(Closure $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException) {
            return;
        }
        self::fail('Expected InvalidArgumentException was not thrown.');
    }
}

/** Deterministic contract fake: it models cursor movement without persistence. */
final class FakeOccurrenceSource implements ExecutionOccurrenceSourceInterface
{
    private int $position = 0;

    /** @param list<ExecutionOccurrenceCandidate> $candidates */
    public function __construct(private array $candidates) {}

    public function next(): ?ExecutionOccurrenceCandidate
    {
        return $this->candidates[$this->position] ?? null;
    }

    public function acknowledge(ExecutionOccurrenceCandidate $candidate, ExecutionAdmission $admission): void
    {
        $current = $this->next();
        if ($current === null || !$current->isSameCandidate($candidate) || !$candidate->matchesAdmission($admission)) {
            throw new InvalidArgumentException('Candidate acknowledgement does not match the current committed admission.');
        }
        ++$this->position;
    }

    public function skip(ExecutionOccurrenceCandidate $candidate, OccurrenceSkipReason $reason): void
    {
        $current = $this->next();
        if ($current === null || !$current->isSameCandidate($candidate)) {
            throw new InvalidArgumentException('Candidate skip does not match the current candidate.');
        }
        ++$this->position;
    }
}
