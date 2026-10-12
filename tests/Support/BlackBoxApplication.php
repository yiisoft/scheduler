<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Support;

use Yiisoft\Schedule\Integration\Async\ExecutionFence;
use Yiisoft\Schedule\Integration\Async\ExecutionIdentity;
use Yiisoft\Schedule\Integration\Async\ExecutionLedgerRecord;
use Yiisoft\Schedule\Integration\Async\ExecutionOccurrenceKey;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerCapability;

use function file_put_contents;

use const FILE_APPEND;
use const LOCK_EX;

final class BlackBoxApplication
{
    public const LEDGER_LOCATOR = 'fixture-ledger';
    public const RUNTIME_LOCATOR = 'fixture-runtime';
    public const TASK_VERSION = 'fixture-task-v1';
    public const CONFIGURATION_FINGERPRINT = 'fixture-manifest-v1';

    public static function capability(ExecutionOccurrenceKey $occurrence, string $fence = 'fixture-fence', string $taskVersion = self::TASK_VERSION): ExecutionWorkerCapability
    {
        return new ExecutionWorkerCapability($occurrence, 'attempt-1', 'run-1', 'execution-1', 1, $fence, self::CONFIGURATION_FINGERPRINT, $taskVersion, self::LEDGER_LOCATOR, self::RUNTIME_LOCATOR, 'source-revision', self::policy());
    }

    public static function record(ExecutionWorkerCapability $capability): ExecutionLedgerRecord
    {
        return new ExecutionLedgerRecord($capability->occurrence, new ExecutionIdentity('fixture-schedule', 'fixture-task', $capability->occurrence->occurrenceId, 'attempt-1', 'run-1', 'execution-1'), 1, new ExecutionFence($capability->generation, $capability->fenceToken), 'launch-required', 'fixture-dispatch', self::CONFIGURATION_FINGERPRINT, self::policy(), 1000, 1000);
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

    private static function policy(): array
    {
        return [
            'format' => 3,
            'source' => ['fixture-source', 'source-revision'],
            'task' => [
                'id' => 'fixture-task', 'mode' => 'async', 'timeout' => null, 'metadata' => [],
                'schedule' => 'fixture-schedule', 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60]],
                'overlap' => 'allow', 'missed' => ['skip', null],
            ],
        ];
    }
}
