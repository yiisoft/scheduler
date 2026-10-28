<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Store;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\{ExecutionDispatchReservation, ExecutionDispatchResult, ExecutionFence, ExecutionIdentity, ExecutionLaunchDescriptor, ExecutionLaunchObservation, ExecutionLedgerRecord, ExecutionLedgerState, ExecutionOccurrenceKey, ExecutionOutcome, ExecutionProcessClosure, ExecutionProcessIdentity, ExecutionRecoveryFence, ExecutionStartObservation, ExecutionTerminalResult};
use Yiisoft\Scheduler\Integration\Async\Store\FileExecutionLedger;

use function getmypid;
use function is_dir;
use function is_file;

final class ProcessFactOrderingTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        static $sequence = 0;
        do {
            $this->directory = sys_get_temp_dir() . '/schedule-process-facts-' . getmypid() . '-' . $sequence++;
        } while (is_dir($this->directory));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->directory);
    }

    public function testTerminalOutcomeBeforeLateStartAndClosurePersistsFacts(): void
    {
        [$ledger, $record] = $this->claimed('terminal');
        $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 20);
        $observation = $this->observation(21, ExecutionProcessClosure::confirmed(0, 'closed'));

        self::assertSame(ExecutionDispatchResult::ACCEPTED, $ledger->observeLaunch($record->occurrence, $record->fence, $this->recovery(), $observation, 'source')->status);
        $stored = (new FileExecutionLedger($this->directory))->observe($record->occurrence);
        self::assertEquals($observation->processIdentity, $stored?->processIdentity);
        self::assertEquals($observation->startObservation, $stored?->attemptStartObservation);
        self::assertNull($stored?->startAnchorIdentity);
        self::assertNull($stored?->startObservation);
        self::assertNull($stored?->recoveryAge);
        self::assertNull($stored?->recoveryDeadline);
        self::assertSame(ExecutionOutcome::SUCCEEDED, $stored?->outcome?->status);
    }

    public function testClaimBeforeStartPersistsIndependentStartFacts(): void
    {
        [$ledger, $record] = $this->claimed('claim', mode: 'async');
        $observation = $this->observation(12, ExecutionProcessClosure::unknown('running'));

        self::assertSame(ExecutionDispatchResult::ACCEPTED, $ledger->observeLaunch($record->occurrence, $record->fence, $this->recovery(), $observation, 'source')->status);
        self::assertEquals($observation->startObservation, $ledger->observe($record->occurrence)?->startObservation);
        self::assertSame(ExecutionLedgerState::CLAIMED, $ledger->observe($record->occurrence)?->state);
    }

    public function testIdentityWithoutTimingSurvivesObservationAndLedgerReload(): void
    {
        [$ledger, $record] = $this->claimed('identity-only');
        $identity = new ExecutionProcessIdentity(123, 123, 123, 'ticks', 'boot');
        $observation = new ExecutionLaunchObservation(123, null, 'identified', 12, ExecutionProcessClosure::unknown('running'), processIdentity: $identity);

        self::assertSame(ExecutionDispatchResult::ACCEPTED, $ledger->observeLaunch($record->occurrence, $record->fence, $this->recovery(), $observation, 'source')->status);
        $stored = (new FileExecutionLedger($this->directory))->observe($record->occurrence);
        self::assertEquals($identity, $stored?->processIdentity);
        self::assertNull($stored?->startObservation);
        self::assertEquals($identity, $stored?->launchObservation?->processIdentity);
        self::assertNull($stored?->launchObservation?->startObservation);
    }

    public function testTimingWithoutIdentityIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExecutionLaunchObservation(123, null, 'invalid', 12, processIdentity: null, startObservation: new ExecutionStartObservation(1000, 2000));
    }

    public function testAsyncClaimWithoutObservedReceiptIsRejected(): void
    {
        [$ledger, $record] = $this->claimed('missing-receipt', claim: false, mode: 'async');

        $claim = $ledger->claim($record->occurrence, $record->fence, 11, 40);

        self::assertFalse($claim->accepted);
        self::assertSame('worker receipt is incomplete', $claim->reason);
        self::assertNull($ledger->observe($record->occurrence)?->launchObservation);
    }

    public function testClosureBeforeOutcomeIsRetained(): void
    {
        [$ledger, $record] = $this->claimed('closure', started: true, mode: 'async');
        $closure = new ExecutionLaunchObservation(null, null, 'closed', 12, ExecutionProcessClosure::confirmed(0, 'closed'));
        self::assertSame(ExecutionDispatchResult::ACCEPTED, $ledger->observeLaunch($record->occurrence, $record->fence, $this->recovery(), $closure, 'source')->status);
        self::assertSame(ExecutionTerminalResult::ACCEPTED, $ledger->recordOutcome($record->occurrence, $record->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 13)->status);
        self::assertTrue($ledger->observe($record->occurrence)?->launchObservation?->closure?->isConfirmed());
    }

    public function testIdenticalObservationIsIdempotentAndConflictingIdentityIsRejected(): void
    {
        [$ledger, $record] = $this->claimed('identity', started: true, mode: 'async');
        $observation = $this->observation(12, ExecutionProcessClosure::unknown('running'));
        $ledger->observeLaunch($record->occurrence, $record->fence, $this->recovery(), $observation, 'source');
        $before = $ledger->observe($record->occurrence);
        self::assertSame(ExecutionDispatchResult::ACCEPTED, $ledger->observeLaunch($record->occurrence, $record->fence, $this->recovery(), $observation, 'source')->status);
        self::assertEquals($before, $ledger->observe($record->occurrence));

        $conflict = new ExecutionLaunchObservation(124, null, 'started', 13, ExecutionProcessClosure::unknown('running'), processIdentity: new ExecutionProcessIdentity(124, 124, 124, 'other', 'boot'), startObservation: new ExecutionStartObservation(1700000000000001, 2000000001));
        self::assertSame(ExecutionDispatchResult::REJECTED, $ledger->observeLaunch($record->occurrence, $record->fence, $this->recovery(), $conflict, 'source')->status);
    }

    /** @return array{0: FileExecutionLedger, 1: ExecutionLedgerRecord} */
    private function claimed(string $id, bool $started = false, bool $claim = true, string $mode = 'sync'): array
    {
        $occurrence = new ExecutionOccurrenceKey('task', $id);
        $startAnchorIdentity = null;
        $record = new ExecutionLedgerRecord($occurrence, new ExecutionIdentity('task', $id, 'attempt', 'run', 'execution'), 1, new ExecutionFence(1, 'fence'), ExecutionLedgerState::LAUNCH_REQUIRED, 'manifest', ['schema' => 'dev', 'source' => ['source', 'revision'], 'task' => ['id' => 'task', 'mode' => $mode, 'timeout' => $mode === 'async' ? [1.0, 2.0] : null, 'recoveryMaxAge' => $mode === 'async' ? 180.0 : null, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]], recoveryFence: $this->recovery(), recoveryAge: null, sourceIdentity: 'source', sourceRevision: 'revision', processIdentity: null, startAnchorIdentity: $startAnchorIdentity, startObservation: null, recoveryDeadline: null);
        $ledger = new FileExecutionLedger($this->directory);
        $ledger->admit($record);
        if ($mode === 'async') {
            $ledger->reserveDispatch($occurrence, $record->fence, new ExecutionDispatchReservation('dispatcher', 10, 30));
            $ledger->recordLaunchDescriptor($occurrence, $record->fence, $this->recovery(), new ExecutionLaunchDescriptor('execution', 1, 'nonce', occurrence: $occurrence), 'source');
        }
        if ($claim) {
            if ($mode === 'async') {
                $descriptor = new ExecutionLaunchDescriptor('execution', 1, 'nonce', occurrence: $occurrence);
                $reservation = $ledger->observe($occurrence)?->dispatchReservation;
                $ledger->launchUnderLock(
                    $occurrence,
                    $record->fence,
                    $this->recovery(),
                    'source',
                    $descriptor,
                    $reservation?->permissionId ?? '',
                    11,
                    fn(): ExecutionLaunchObservation => $this->observation(11, ExecutionProcessClosure::unknown('running')),
                    nowUs: 11_000_000,
                );
                $ledger->claim($occurrence, $record->fence, 11, 40, $descriptor, $reservation?->permissionId);
            } else {
                $ledger->claim($occurrence, $record->fence, 11, 40);
            }
        }
        return [$ledger, $record];
    }

    private function observation(int $at, ExecutionProcessClosure $closure): ExecutionLaunchObservation
    {
        return new ExecutionLaunchObservation(123, null, 'observed', $at, $closure, processIdentity: new ExecutionProcessIdentity(123, 123, 123, 'ticks', 'boot'), startObservation: new ExecutionStartObservation(1700000000000000, 2000000000));
    }

    private function recovery(): ExecutionRecoveryFence
    {
        return new ExecutionRecoveryFence('recovery', 1);
    }
}
