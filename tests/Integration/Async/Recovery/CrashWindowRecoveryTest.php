<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Recovery;

use Yiisoft\Scheduler\Tests\Support\SQLiteLedgerFixture;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yiisoft\Scheduler\Config\PreparedConfiguration;
use Yiisoft\Scheduler\Integration\Async\Store\SQLiteExecutionLedger;
use Yiisoft\Scheduler\Integration\Async\{
    ExecutionDispatchReservation, ExecutionFence, ExecutionIdentity,
    ExecutionLaunchDescriptor, ExecutionLaunchObservation, ExecutionLedgerRecord, ExecutionLedgerState,
    ExecutionOccurrenceKey, ExecutionOutcome, ExecutionProcessIdentity, ExecutionRecoveryFence,
    ExecutionStartObservation, ExecutionWorkerCapability, ExecutionWorkerClaimRejectedException,
    ExecutionWorkerRuntimeComposer, ExecutionWorkerRuntimeFactoryInterface, ExecutionWorkerRuntimeInterface,
};
use Yiisoft\Scheduler\Integration\Async\ExecutionLedgerCommandInterface;

use function hash;
use function getmypid;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sprintf;
use function unlink;

/** Deterministic, provider-free coverage of the parent/receipt crash barriers. */
final class CrashWindowRecoveryTest extends TestCase
{
    private string $directory = '';

    private static int $number = 0;

    protected function setUp(): void
    {
        do {
            $this->directory = sys_get_temp_dir() . '/schedule-crash-window-' . (int) getmypid() . '-' . self::$number++;
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

    public function testParentCrashBeforeExternalStartDoesNotInventReceiptAndRepairsPermission(): void
    {
        [$ledger, $record, $reservation, $descriptor] = $this->prepared();
        $factory = new CrashWindowFactory();
        $capability = $this->capability($record, $descriptor, $reservation->permissionId);

        $this->assertRejectedWithoutBootstrap($ledger, $factory, $capability, $descriptor, $reservation->permissionId);
        $stored = $ledger->observe($record->occurrence);
        self::assertSame(ExecutionDispatchReservation::PREPARED, $stored?->dispatchReservation?->launchPhase);
        self::assertNull($stored?->launchObservation);
        self::assertNull($stored?->startObservation);
        self::assertNull($stored?->recoveryDeadline);

        // A pre-start absence proof is only meaningful after the durable STARTING barrier.
        $ambiguous = $ledger->launchUnderLock($record->occurrence, $record->fence, $this->recoveryFence($record), 'source-1', $descriptor, $reservation->permissionId, 11, static function (): never {
            throw new RuntimeException('parent died before external start');
        }, nowUs: 11_000_000);
        self::assertSame(ExecutionLedgerState::LAUNCH_AMBIGUOUS, $ambiguous->record?->state);
        $repaired = $ledger->repairBeforeStartUnderLock($record->occurrence, $record->fence, $this->recoveryFence($record), 'source-1', static fn(): bool => true, 12_000_000);
        self::assertSame('same-generation-unclaimed', $repaired->status);
        self::assertNotSame($reservation->permissionId, $repaired->record?->dispatchReservation?->permissionId);
        self::assertSame($record->sourceRevision, $repaired->record?->sourceRevision);
        self::assertEquals($record->fence, $repaired->record?->fence);
        self::assertNull($repaired->record?->launchObservation);
        self::assertNull($repaired->record?->recoveryDeadline);
    }

    public function testExternalStartBeforeReceiptRequiresAbsenceProofAndPreservesRecoveryAnchor(): void
    {
        [$ledger, $record, $reservation, $descriptor] = $this->prepared();
        $result = $ledger->launchUnderLock($record->occurrence, $record->fence, $this->recoveryFence($record), 'source-1', $descriptor, $reservation->permissionId, 11, static function (): never {
            throw new RuntimeException('receipt publication crashed');
        }, nowUs: 11_000_000);
        self::assertSame('ambiguous', $result->status);
        self::assertFalse($ledger->claim($record->occurrence, $record->fence, 12, 20, $descriptor, $reservation->permissionId)->accepted);
        self::assertSame('rejected', $ledger->repairBeforeStartUnderLock($record->occurrence, $record->fence, $this->recoveryFence($record), 'source-1', static fn(): bool => false, 13_000_000)->status);

        $repaired = $ledger->repairBeforeStartUnderLock($record->occurrence, $record->fence, $this->recoveryFence($record), 'source-1', static fn(): bool => true, 13_000_000);
        self::assertSame('same-generation-unclaimed', $repaired->status);
        self::assertNotSame($reservation->permissionId, $repaired->record?->dispatchReservation?->permissionId);
        self::assertSame($record->sourceIdentity, $repaired->record?->sourceIdentity);
        self::assertSame($record->sourceRevision, $repaired->record?->sourceRevision);
        self::assertEquals($record->fence, $repaired->record?->fence);
        self::assertNull($repaired->record?->startAnchorIdentity);
        self::assertNull($repaired->record?->startObservation);
        self::assertNull($repaired->record?->recoveryDeadline);
        self::assertSame(23, $repaired->record?->dispatchReservation?->deadline);
    }

    public function testReceiptPublicationPrecedesExactClaimAndOnlyExactClaimBootstraps(): void
    {
        [$ledger, $record, $reservation, $descriptor] = $this->prepared();
        $identity = new ExecutionProcessIdentity(42, 42, 42, 'start-ticks', 'boot');
        $start = new ExecutionStartObservation(1700000000000000, 42);
        $launch = $ledger->launchUnderLock($record->occurrence, $record->fence, $this->recoveryFence($record), 'source-1', $descriptor, $reservation->permissionId, 11, static fn(): ExecutionLaunchObservation => new ExecutionLaunchObservation(42, null, 'started', 11, processIdentity: $identity, startObservation: $start), nowUs: 11_000_000);
        self::assertSame('accepted', $launch->status);
        self::assertSame(ExecutionDispatchReservation::OBSERVED, $launch->record?->dispatchReservation?->launchPhase);
        self::assertSame(1700000180000000, $launch->record?->recoveryDeadline);

        $factory = new CrashWindowFactory();
        $badDescriptor = new ExecutionLaunchDescriptor('execution-1', 1, 'wrong', occurrence: $record->occurrence);
        $this->assertRejectedWithoutBootstrap($ledger, $factory, $this->capability($record, $descriptor, $reservation->permissionId), $badDescriptor, $reservation->permissionId);
        $this->assertRejectedWithoutBootstrap($ledger, $factory, $this->capability($record, $descriptor, str_repeat('b', 32)), $descriptor, str_repeat('b', 32));
        self::assertSame(0, $factory->calls);

        $runtime = (new ExecutionWorkerRuntimeComposer($ledger, $factory))->compose(
            $this->capability($record, $descriptor, $reservation->permissionId),
            1700000000000010,
            1700000000000020,
            $descriptor,
            $reservation->permissionId,
        );
        self::assertSame(1, (int) sprintf('%d', $factory->calls));
        self::assertInstanceOf(ExecutionWorkerRuntimeInterface::class, $runtime);
        self::assertSame(ExecutionLedgerState::CLAIMED, $ledger->observe($record->occurrence)?->state);
        self::assertFalse($ledger->claim($record->occurrence, $record->fence, 1700000000000011, 20, $descriptor, $reservation->permissionId)->accepted);
    }

    /** @return array{SQLiteExecutionLedger, ExecutionLedgerRecord, ExecutionDispatchReservation, ExecutionLaunchDescriptor} */
    private function prepared(?ExecutionProcessIdentity $anchor = null, ?ExecutionStartObservation $start = null, ?int $deadline = null): array
    {
        $ledger = SQLiteLedgerFixture::ledger($this->directory);
        $record = $this->record($anchor, $start, $deadline);
        $reservation = new ExecutionDispatchReservation('dispatcher', 10, 20);
        $descriptor = new ExecutionLaunchDescriptor('execution-1', 1, 'nonce-1', occurrence: $record->occurrence);
        $ledger->admit($record);
        $ledger->reserveDispatch($record->occurrence, $record->fence, $reservation);
        $ledger->recordLaunchDescriptor($record->occurrence, $record->fence, $this->recoveryFence($record), $descriptor, 'source-1');
        return [$ledger, $record, $reservation, $descriptor];
    }

    private function record(?ExecutionProcessIdentity $anchor = null, ?ExecutionStartObservation $start = null, ?int $deadline = null): ExecutionLedgerRecord
    {
        $occurrence = new ExecutionOccurrenceKey('task', 'occurrence');
        return new ExecutionLedgerRecord($occurrence, new ExecutionIdentity('task', 'occurrence', 'attempt', 'run-1', 'execution-1'), 1, new ExecutionFence(1, 'fence-1'), ExecutionLedgerState::LAUNCH_REQUIRED, 'manifest', $this->policy(), createdAt: 1, updatedAt: 1, recoveryFence: new ExecutionRecoveryFence('recovery-1', 1), sourceIdentity: 'source-1', sourceRevision: 'revision-1', startAnchorIdentity: $anchor, startObservation: $start, recoveryAge: $deadline === null ? null : 180.0, recoveryDeadline: $deadline);
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        return ['schema' => 'dev', 'source' => ['source-1', 'revision-1'], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => null, 'recoveryMaxAge' => 180.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]];
    }

    private function capability(ExecutionLedgerRecord $record, ExecutionLaunchDescriptor $descriptor, string $permission): ExecutionWorkerCapability
    {
        return new ExecutionWorkerCapability($record->occurrence, 'attempt', 'run-1', 'execution-1', 1, 'fence-1', 'manifest', hash('sha256', PreparedConfiguration::canonical($this->policy())), 'ledger://test', 'runtime://test', 'revision-1', $this->policy(), $this->recoveryFence($record), $descriptor, $permission);
    }

    private function recoveryFence(ExecutionLedgerRecord $record): ExecutionRecoveryFence
    {
        self::assertNotNull($record->recoveryFence);
        return $record->recoveryFence;
    }

    private function assertRejectedWithoutBootstrap(SQLiteExecutionLedger $ledger, CrashWindowFactory $factory, ExecutionWorkerCapability $capability, ExecutionLaunchDescriptor $descriptor, string $permission): void
    {
        try {
            (new ExecutionWorkerRuntimeComposer($ledger, $factory))->compose($capability, 12, 20, $descriptor, $permission);
            self::fail('Invalid launch handoff was accepted.');
        } catch (ExecutionWorkerClaimRejectedException) {
            self::assertSame(0, $factory->calls);
        }
    }
}

final class CrashWindowFactory implements ExecutionWorkerRuntimeFactoryInterface
{
    public int $calls = 0;

    public function create(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $claimedRecord, ExecutionLedgerCommandInterface $ledger): ExecutionWorkerRuntimeInterface
    {
        ++$this->calls;
        return new class implements ExecutionWorkerRuntimeInterface {
            public function execute(): ExecutionOutcome
            {
                return new ExecutionOutcome(ExecutionOutcome::SUCCEEDED);
            }
        };
    }
}
