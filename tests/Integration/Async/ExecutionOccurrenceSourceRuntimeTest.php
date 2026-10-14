<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\{MissedOccurrencePolicy, MissedOccurrenceSetting, TimeoutPolicy};
use Yiisoft\Scheduler\Integration\Async\{ExecutionAdmission, ExecutionBinding, ExecutionBindingSourceInterface, ExecutionClaimResult, ExecutionDispatchReservation, ExecutionDispatchResult, ExecutionFence, ExecutionGenerationResult, ExecutionIdentity, ExecutionLedgerInterface, ExecutionLedgerRecord, ExecutionOccurrenceCheckpointInterface, ExecutionOccurrenceKey, ExecutionOccurrenceSource, OccurrenceSkipReason, ExecutionOutcome, ExecutionRecoveryFence, ExecutionRecoveryResult, ExecutionRevocationResult, ExecutionLaunchObservation, ExecutionTerminalResult};
use Yiisoft\Scheduler\Tests\Support\TestClock;
use Yiisoft\Scheduler\Trigger\{CronExpressionTrigger, PeriodicTrigger, TriggerInterface};
use Yiisoft\Scheduler\Integration\Async\ExecutionLedgerState;
use Yiisoft\Scheduler\Integration\Async\ExecutionOccurrenceCandidate;
use InvalidArgumentException;
use LogicException;
use DateTimeZone;

final class ExecutionOccurrenceSourceRuntimeTest extends TestCase
{
    public function testFirstRunReplayPoliciesSkipHistorylessSlotsAndNeverAdmit(): void
    {
        foreach ([MissedOccurrencePolicy::LAST, new MissedOccurrenceSetting(MissedOccurrencePolicy::ALL, 2)] as $policy) {
            $ledger = new RuntimeLedger(false);
            $checkpoint = new RuntimeCheckpoint('t0000036000');
            $source = $this->source($policy, $ledger, $checkpoint);

            self::assertSame('o0000043200', $source->next()?->occurrence->occurrenceId);
            self::assertSame(0, $ledger->admissions);
        }
    }

    public function testLastReturnsNewestMissedAfterHistoryAndAcknowledgementCommitsIt(): void
    {
        $ledger = new RuntimeLedger(true);
        $checkpoint = new RuntimeCheckpoint('t0000036000');
        $source = $this->source(new MissedOccurrenceSetting(MissedOccurrencePolicy::LAST), $ledger, $checkpoint);

        $candidate = $source->next();
        self::assertSame('o0000042900', $candidate?->occurrence->occurrenceId);
        $source->acknowledge($candidate, new ExecutionAdmission($this->record($candidate), true));
        self::assertSame([['expected' => 't0000036000', 'next' => 't0000042900']], $checkpoint->advancements);
        self::assertSame('t0000042900', $checkpoint->value);
        self::assertSame(0, $ledger->admissions);
    }

    public function testAllReturnsChronologicalBoundedReplayAndSkipsOldestExcess(): void
    {
        $ledger = new RuntimeLedger(true);
        $checkpoint = new RuntimeCheckpoint('t0000036000');
        $source = $this->source(new MissedOccurrenceSetting(MissedOccurrencePolicy::ALL, 2), $ledger, $checkpoint);

        $first = $source->next();
        self::assertSame('o0000042600', $first?->occurrence->occurrenceId);
        $source->acknowledge($first, new ExecutionAdmission($this->record($first), true));
        $second = $source->next();
        self::assertSame('o0000042900', $second?->occurrence->occurrenceId);
        $source->acknowledge($second, new ExecutionAdmission($this->record($second), false));
        self::assertSame('o0000043200', $source->next()?->occurrence->occurrenceId);
        self::assertSame(0, $ledger->admissions);
    }

    public function testExactNowIsInclusiveAndRestartOrDuplicateAcknowledgementDoesNotDuplicate(): void
    {
        $ledger = new RuntimeLedger(true);
        $checkpoint = new RuntimeCheckpoint('t0000039300');
        $clock = new TestClock(new DateTimeImmutable('1970-01-01 11:00:00+00:00'));
        $source = $this->source(new MissedOccurrenceSetting(MissedOccurrencePolicy::LAST), $ledger, $checkpoint, $clock);
        $candidate = $source->next();
        self::assertSame('o0000039600', $candidate?->occurrence->occurrenceId);
        $admission = new ExecutionAdmission($this->record($candidate), true);
        $source->acknowledge($candidate, $admission);
        $restarted = $this->source(new MissedOccurrenceSetting(MissedOccurrencePolicy::LAST), $ledger, $checkpoint, $clock);
        self::assertSame('o0000039900', $restarted->next()?->occurrence->occurrenceId);
        $this->expectException(InvalidArgumentException::class);
        $source->acknowledge($candidate, $admission);
    }

    public function testFreshAnchoredCronAdmitsItsConfiguredFirstOccurrence(): void
    {
        $from = new DateTimeImmutable('2000-01-01 00:00:00+00:00');
        $checkpoint = new RuntimeCheckpoint('t0946684799');
        $source = new ExecutionOccurrenceSource(
            'task',
            new CronExpressionTrigger('* * * * *', from: $from),
            new RuntimeBindingFactory(),
            new TestClock(new DateTimeImmutable('2026-01-01 00:00:00+00:00')),
            $checkpoint,
            initialAnchorCursor: 't0946684799',
        );

        $candidate = $source->next();
        self::assertSame('o0946684860', $candidate?->occurrence->occurrenceId);

        // Simulate a crash after checkpoint initialization but before admission:
        // the replacement source must reconstruct the deliberate first anchor.
        $restarted = new ExecutionOccurrenceSource(
            'task',
            new CronExpressionTrigger('* * * * *', from: $from),
            new RuntimeBindingFactory(),
            new TestClock(new DateTimeImmutable('2026-01-01 00:00:00+00:00')),
            $checkpoint,
            initialAnchorCursor: 't0946684799',
        );
        self::assertSame('o0946684860', $restarted->next()?->occurrence->occurrenceId);

        $source->acknowledge($candidate, new ExecutionAdmission($this->record($candidate), true));
        self::assertSame('t0946684860', $checkpoint->value);
        self::assertSame('o1767225600', $source->next()?->occurrence->occurrenceId);
    }

    public function testAnchoredCronAfterHistoryUsesMissedOccurrencePolicyInsteadOfFreshAnchor(): void
    {
        $from = new DateTimeImmutable('2000-01-01 00:00:00+00:00');
        $ledger = new RuntimeLedger(true);
        $source = new ExecutionOccurrenceSource(
            'task',
            new CronExpressionTrigger('* * * * *', from: $from),
            new RuntimeBindingFactory(),
            new TestClock(new DateTimeImmutable('2000-01-01 00:03:00+00:00')),
            new RuntimeCheckpoint('t0946684799'),
            $ledger,
            new MissedOccurrenceSetting(MissedOccurrencePolicy::LAST),
            't0946684799',
        );

        self::assertSame('o0946684920', $source->next()?->occurrence->occurrenceId);
    }

    public function testDefaultSkipPreservesUnanchoredPeriodicPhaseAtExactNow(): void
    {
        $source = new ExecutionOccurrenceSource(
            'task',
            new PeriodicTrigger(300),
            new RuntimeBindingFactory(),
            new TestClock(new DateTimeImmutable('1970-01-01 00:10:00+00:00')),
            new RuntimeCheckpoint('t0000000000'),
        );

        self::assertSame('o0000000600', $source->next()?->occurrence->occurrenceId);
    }

    public function testValidSkipAdvancesProductionSourceCursor(): void
    {
        $checkpoint = new RuntimeCheckpoint('t0000000000');
        $source = new ExecutionOccurrenceSource(
            'task',
            new PeriodicTrigger(300),
            new RuntimeBindingFactory(),
            new TestClock(new DateTimeImmutable('1970-01-01 00:10:00+00:00')),
            $checkpoint,
        );
        $candidate = $source->next();
        self::assertNotNull($candidate);

        $source->skip($candidate, OccurrenceSkipReason::OVERLAP);
        self::assertSame('t0000000600', $checkpoint->value);
    }

    public function testNoRangeFallbackIsBoundedAndEventuallyFindsFutureCandidate(): void
    {
        $trigger = new QuotaTrigger();
        $source = new ExecutionOccurrenceSource(
            'task',
            $trigger,
            new RuntimeBindingFactory(),
            new TestClock(new DateTimeImmutable('1970-01-01 00:10:00+00:00')),
            new RuntimeCheckpoint('t0000000000'),
            MissedOccurrencePolicy::SKIP,
        );

        $found = null;
        for ($i = 0; $i < 4 && $found === null; ++$i) {
            $before = $trigger->calls;
            $found = $source->next();
            self::assertLessThanOrEqual(64, $trigger->calls - $before);
        }
        self::assertSame('o0000001000', $found?->occurrence->occurrenceId);
    }

    private function source(MissedOccurrenceSetting|MissedOccurrencePolicy $policy, RuntimeLedger $ledger, RuntimeCheckpoint $checkpoint, ?TestClock $clock = null): ExecutionOccurrenceSource
    {
        return new ExecutionOccurrenceSource('task', new PeriodicTrigger(300, new DateTimeImmutable('1970-01-01 10:00:00+00:00')), new RuntimeBindingFactory(), $clock ?? new TestClock(new DateTimeImmutable('1970-01-01 12:00:00+00:00')), $checkpoint, $ledger, $policy);
    }

    private function record(ExecutionOccurrenceCandidate $candidate): ExecutionLedgerRecord
    {
        return new ExecutionLedgerRecord($candidate->occurrence, new ExecutionIdentity(
            $candidate->mapping->correlation->taskKey,
            $candidate->mapping->correlation->occurrenceId,
            $candidate->mapping->correlation->attemptId,
            $candidate->mapping->correlation->runId,
            $candidate->mapping->correlation->executionId,
        ), 1, new ExecutionFence(1, 'fence'), ExecutionLedgerState::LAUNCH_REQUIRED, 'launch', $candidate->mapping->configurationFingerprint, $candidate->mapping->payload, 1, 1, metadata: ['occurrenceCursor' => $candidate->cursor, 'taskMappingIdentity' => $candidate->taskMappingIdentity(), 'taskMappingContext' => $candidate->mapping->context]);
    }
}

final class QuotaTrigger implements TriggerInterface
{
    public int $calls = 0;

    public function __toString(): string
    {
        return 's13-quota';
    }

    public function getNextRunDate(DateTimeImmutable $lastRun): ?DateTimeImmutable
    {
        ++$this->calls;
        if ($this->calls <= 70) {
            return (new DateTimeImmutable('@' . $this->calls))->setTimezone(new DateTimeZone('UTC'));
        }
        return $this->calls === 71 ? new DateTimeImmutable('1970-01-01 00:16:40+00:00') : null;
    }
}

final class RuntimeCheckpoint implements ExecutionOccurrenceCheckpointInterface
{
    /** @var list<array{expected: string, next: string}> */
    public array $advancements = [];

    public function __construct(public string $value) {}

    public function read(string $taskKey): ?string
    {
        return $this->value;
    }

    public function initialize(string $taskKey, string $cursor): void
    {
        if ($this->value !== '') {
            throw new InvalidArgumentException('Checkpoint is already initialized.');
        }
        $this->value = $cursor;
    }

    public function advance(string $taskKey, string $expectedCursor, string $nextCursor): void
    {
        if ($this->value !== $expectedCursor || $expectedCursor === $nextCursor) {
            throw new InvalidArgumentException('Checkpoint advancement is not an exact CAS.');
        }
        $this->advancements[] = ['expected' => $expectedCursor, 'next' => $nextCursor];
        $this->value = $nextCursor;
    }

    public function skip(string $taskKey, string $expectedCursor, string $nextCursor, OccurrenceSkipReason $reason): void
    {
        if ($this->value !== $expectedCursor || $expectedCursor === $nextCursor) {
            throw new InvalidArgumentException('Checkpoint skip is invalid.');
        }
        $this->value = $nextCursor;
    }
}

final class RuntimeBindingFactory implements ExecutionBindingSourceInterface
{
    public function map(string $taskKey, string $occurrenceId, int $occurrenceTime): ExecutionBinding
    {
        return new ExecutionBinding($taskKey, $occurrenceId, $occurrenceTime, new ExecutionIdentity($taskKey, $occurrenceId, 'attempt', 'run', 'execution'), 'manifest', payload: ['format' => 4, 'source' => [null, null], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => [300.0, null], 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]], runId: 'run', timeout: new TimeoutPolicy(300.0), mode: 'async');
    }
}

final class RuntimeLedger implements ExecutionLedgerInterface
{
    public int $admissions = 0;

    public function __construct(private readonly bool $history) {}

    public function admit(ExecutionLedgerRecord $record): ExecutionAdmission
    {
        ++$this->admissions;
        return new ExecutionAdmission($record, true);
    }

    public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
    {
        return null;
    }

    public function hasTaskHistory(string $taskKey): bool
    {
        return $this->history;
    }

    public function claim(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, int $at, ?int $claimLeaseDeadline): ExecutionClaimResult
    {
        throw new LogicException();
    }

    public function reserveDispatch(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionDispatchReservation $reservation): ExecutionDispatchResult
    {
        throw new LogicException();
    }

    public function redispatchSameGeneration(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionDispatchReservation $reservation, int $at): ExecutionDispatchResult
    {
        throw new LogicException();
    }

    public function observeLaunch(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionLaunchObservation $observation): ExecutionDispatchResult
    {
        throw new LogicException();
    }

    public function recover(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $fence, int $at): ExecutionRecoveryResult
    {
        throw new LogicException();
    }

    public function revoke(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $fence, int $at): ExecutionRevocationResult
    {
        throw new LogicException();
    }

    public function allocateGeneration(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $fence, ExecutionFence $newFence, int $at): ExecutionGenerationResult
    {
        throw new LogicException();
    }

    public function recordOutcome(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionOutcome $outcome, int $at): ExecutionTerminalResult
    {
        throw new LogicException();
    }
}
