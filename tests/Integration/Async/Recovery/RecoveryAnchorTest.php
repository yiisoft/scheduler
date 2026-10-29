<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Recovery;

use Yiisoft\Scheduler\Integration\Async\Store\ExecutionLedgerPayloadCodec;
use Yiisoft\Scheduler\Tests\Support\SQLiteLedgerFixture;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\{ExecutionDispatchReservation, ExecutionFence, ExecutionIdentity, ExecutionLaunchObservation, ExecutionLedgerRecord, ExecutionLedgerState, ExecutionOccurrenceKey, ExecutionProcessClosure, ExecutionRecoveryFence, ExecutionProcessIdentity, ExecutionStartObservation};
use Yiisoft\Scheduler\Integration\Async\Recovery\RecoveryDeadline;
use RuntimeException;

use function getmypid;
use function is_dir;
use function mkdir;
use function sys_get_temp_dir;

use const INF;
use const NAN;
use const PHP_INT_MAX;

final class RecoveryAnchorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        static $sequence = 0;
        do {
            $this->directory = sys_get_temp_dir() . '/schedule-recovery-' . getmypid() . '-' . $sequence++;
        } while (is_dir($this->directory));

        if (!mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create the recovery test directory.');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testNoRecoveryAnchorExistsBeforeCompleteStart(): void
    {
        $record = $this->record();
        $codec = new ExecutionLedgerPayloadCodec();

        self::assertNull(RecoveryDeadline::forRecord($record));
        self::assertNull(RecoveryDeadline::forRecord($codec->decode($codec->encode($record))));
    }

    /** @dataProvider durationProvider */
    public function testDurationUsesAnExactNonEarlyMicrosecondCeiling(mixed $seconds, int $expected): void
    {
        self::assertSame($expected, RecoveryDeadline::durationMicroseconds($seconds));
    }

    public static function durationProvider(): array
    {
        return [[1, 1000000], [1.0000001, 1000001], [1.0000000000000011, 1000001], [0.1000001, 100001], [1.23456789, 1234568]];
    }

    /** @dataProvider invalidDurationProvider */
    public function testInvalidDurationIsRejected(mixed $seconds): void
    {
        $this->expectException(InvalidArgumentException::class);
        RecoveryDeadline::durationMicroseconds($seconds);
    }

    public static function invalidDurationProvider(): array
    {
        return [[0], [-1], [INF], [NAN], ['1 second'], [PHP_INT_MAX]];
    }

    public function testAllocationAndCodecPreserveOriginalAnchorAndDeadline(): void
    {
        $record = $this->record(new ExecutionStartObservation(1700000000000000, 1000000000));
        $ledger = SQLiteLedgerFixture::ledger($this->directory);
        $ledger->admit($record);
        $recoveryFence = new ExecutionRecoveryFence('recovery', 1);
        $reservation = new ExecutionDispatchReservation('dispatcher', 10, 20);
        self::assertSame('accepted', $ledger->reserveDispatch($record->occurrence, $record->fence, $reservation)->status);
        $observed = $ledger->observeLaunch(
            $record->occurrence,
            $record->fence,
            $recoveryFence,
            new ExecutionLaunchObservation(
                $record->processIdentity->pid,
                null,
                'closed',
                11,
                ExecutionProcessClosure::confirmed(0, 'closed'),
                processIdentity: $record->processIdentity,
                startObservation: $record->startObservation,
            ),
            'source',
        );
        self::assertSame('ambiguous', $observed->status);
        $revoked = $ledger->revoke($record->occurrence, $recoveryFence, 20_000_000, $record->fence, 'source');
        self::assertSame('accepted', $revoked->status);
        $allocated = $ledger->allocateGeneration($record->occurrence, $recoveryFence, new ExecutionFence(2, 'fence-2'), 21_000_000, $record->fence, 'source');
        $next = $allocated->record;

        self::assertNotNull($next);
        self::assertEquals($record->startAnchorIdentity, $next->startAnchorIdentity);
        self::assertEquals($record->startObservation, $next->startObservation);
        self::assertIsInt($next->recoveryDeadline);
        self::assertSame(1700000002000000, $next->recoveryDeadline);
        self::assertSame($record->recoveryDeadline, $next->recoveryDeadline);
        self::assertSame($record->recoveryAge, $next->recoveryAge);
        $reopened = SQLiteLedgerFixture::ledger($this->directory);
        self::assertEquals($next->startAnchorIdentity, $reopened->observe($record->occurrence)?->startAnchorIdentity);
        self::assertSame($next->recoveryDeadline, $reopened->observe($record->occurrence)?->recoveryDeadline);
    }

    public function testReattachmentRetainsOriginalDeadlineWhileNewAttemptTimingIsSeparate(): void
    {
        $original = new ExecutionStartObservation(1000000, 1000000000);
        $attempt = new ExecutionStartObservation(2000000, 4000000000);
        $record = $this->record($original, $attempt);
        $reattached = $record->transition(attemptStartObservation: $attempt);

        self::assertSame(3000000, RecoveryDeadline::forRecord($reattached));
        self::assertSame($record->startAnchorIdentity, $reattached->startAnchorIdentity);
        self::assertSame($original, $reattached->startObservation);
        self::assertSame($attempt, $reattached->attemptStartObservation);
        self::assertNotSame($reattached->attemptStartObservation, $reattached->startObservation);
    }

    private function record(?ExecutionStartObservation $start = null, ?ExecutionStartObservation $attempt = null): ExecutionLedgerRecord
    {
        $recoveryMaxAge = 1.0;
        $hardTimeout = 2.0;
        $recoveryDeadline = $start === null
            ? null
            : $start->wallMicroseconds + RecoveryDeadline::durationMicroseconds(max($recoveryMaxAge, $hardTimeout));
        $processIdentity = $start === null ? null : new ExecutionProcessIdentity(1, 1, 1, 'ticks', 'boot');

        return new ExecutionLedgerRecord(
            occurrence: new ExecutionOccurrenceKey('task', 'occurrence'),
            correlation: new ExecutionIdentity('task', 'occurrence', 'attempt-1', 'run-1', 'execution-1'),
            attempt: 1,
            fence: new ExecutionFence(1, 'fence-1'),
            state: ExecutionLedgerState::LAUNCH_REQUIRED,
            configurationFingerprint: 'manifest-v1',
            taskSnapshot: ['schema' => 'dev', 'source' => ['source', 'revision'], 'task' => [
                'id' => 'task', 'mode' => 'async', 'timeout' => [1.0, 2.0], 'recoveryMaxAge' => 1.0,
                'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]],
                'overlap' => 'allow', 'missed' => ['skip', null],
            ]],
            createdAt: 10,
            updatedAt: 10,
            recoveryFence: new ExecutionRecoveryFence('recovery', 1),
            sourceIdentity: 'source',
            sourceRevision: 'revision',
            processIdentity: $processIdentity,
            startAnchorIdentity: $processIdentity,
            startObservation: $start,
            attemptStartObservation: $attempt,
            recoveryAge: $start === null ? null : $recoveryMaxAge,
            recoveryDeadline: $recoveryDeadline,
        );
    }
}
