<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use Yiisoft\Scheduler\Config\PreparedConfiguration;
use Yiisoft\Scheduler\Integration\Async\ExecutionDispatchReservation;
use Yiisoft\Scheduler\Integration\Async\ExecutionFence;
use Yiisoft\Scheduler\Integration\Async\ExecutionIdentity;
use Yiisoft\Scheduler\Integration\Async\ExecutionRecoveryFence;
use Yiisoft\Scheduler\Integration\Async\ExecutionLedgerRecord;
use Yiisoft\Scheduler\Integration\Async\ExecutionOccurrenceKey;
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchDescriptor;
use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerCapability;
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchObservation;
use Yiisoft\Scheduler\Integration\Async\ExecutionProcessIdentity;
use Yiisoft\Scheduler\Integration\Async\ExecutionStartObservation;

use function file_put_contents;

use const FILE_APPEND;
use const LOCK_EX;

final class BlackBoxApplication
{
    public const LEDGER_LOCATOR = 'fixture-ledger';
    public const RUNTIME_LOCATOR = 'fixture-runtime';
    public const CONFIGURATION_FINGERPRINT = 'fixture-manifest-v1';

    public static function capability(ExecutionOccurrenceKey $occurrence, string $fence = 'fixture-fence', ?string $taskVersion = null): ExecutionWorkerCapability
    {
        $policy = self::policy();
        $taskVersion ??= hash('sha256', PreparedConfiguration::canonical($policy));

        return new ExecutionWorkerCapability($occurrence, 'attempt-1', 'run-1', 'execution-1', 1, $fence, self::CONFIGURATION_FINGERPRINT, $taskVersion, self::LEDGER_LOCATOR, self::RUNTIME_LOCATOR, 'source-revision', $policy, new ExecutionRecoveryFence('fixture-recovery', 1), self::descriptor($occurrence), self::permissionId());
    }

    public static function record(ExecutionWorkerCapability $capability): ExecutionLedgerRecord
    {
        $identity = new ExecutionProcessIdentity(123, 123, 123, 'fixture-start', 'fixture-boot');
        $start = new ExecutionStartObservation(1001, 1);
        return new ExecutionLedgerRecord(
            $capability->occurrence,
            new ExecutionIdentity('fixture-task', $capability->occurrence->occurrenceId, 'attempt-1', 'run-1', 'execution-1'),
            1,
            new ExecutionFence($capability->generation, $capability->fenceToken),
            'needs-resolution',
            self::CONFIGURATION_FINGERPRINT,
            self::policy(),
            1000,
            1000,
            dispatchReservation: new ExecutionDispatchReservation('fixture-dispatcher', 1000, 2000, self::permissionId(), ExecutionDispatchReservation::OBSERVED),
            launchObservation: new ExecutionLaunchObservation(123, null, 'started', 1001, processIdentity: $identity, startObservation: $start),
            recoveryFence: $capability->recoveryFence,
            sourceIdentity: 'fixture-source',
            sourceRevision: 'source-revision',
            launchDescriptor: $capability->launchDescriptor,
            processIdentity: $identity,
            startAnchorIdentity: $identity,
            startObservation: $start,
            attemptStartObservation: $start,
            recoveryAge: 180.0,
            recoveryDeadline: 180001001,
        );
    }

    /** The callable is intentionally application-owned and is never encoded in the capability. */
    public static function fixtureCallable(string $effectPath): callable
    {
        return static function () use ($effectPath): void {
            file_put_contents($effectPath, "fixture-effect\n", FILE_APPEND | LOCK_EX);
        };
    }

    public static function invokeFixtureSynchronously(string $effectPath): void
    {
        (self::fixtureCallable($effectPath))();
    }

    private static function descriptor(ExecutionOccurrenceKey $occurrence): ExecutionLaunchDescriptor
    {
        return new ExecutionLaunchDescriptor('execution-1', 1, 'fixture-worker', occurrence: $occurrence);
    }

    private static function permissionId(): string
    {
        return 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    }

    private static function policy(): array
    {
        return [
            'schema' => 'dev',
            'source' => ['fixture-source', 'source-revision'],
            'task' => [
                'id' => 'fixture-task', 'mode' => 'async', 'timeout' => null, 'recoveryMaxAge' => 180.0, 'metadata' => [],
                'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60]],
                'overlap' => 'allow', 'missed' => ['skip', null],
            ],
        ];
    }
}
