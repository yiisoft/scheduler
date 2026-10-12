<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function in_array;

/** Explicit lifecycle states for one durable ledger record. */
final class ExecutionLedgerState
{
    public const LAUNCH_REQUIRED = 'launch-required';
    public const LAUNCH_REQUESTED = 'launch-requested';
    public const CLAIMED = 'claimed';
    public const RUNNING = 'running';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const LAUNCH_AMBIGUOUS = 'launch-ambiguous';
    public const NEEDS_RESOLUTION = 'needs-resolution';
    public const REVOKED = 'revoked';

    private const ALL = [self::LAUNCH_REQUIRED, self::LAUNCH_REQUESTED, self::CLAIMED, self::RUNNING, self::SUCCEEDED, self::FAILED, self::CANCELLED, self::LAUNCH_AMBIGUOUS, self::NEEDS_RESOLUTION, self::REVOKED];
    private const TERMINAL = [self::SUCCEEDED, self::FAILED, self::CANCELLED];

    public static function assertValid(string $state): void
    {
        if (!in_array($state, self::ALL, true)) {
            throw new InvalidArgumentException('Invalid execution ledger state.');
        }
    }

    public static function isTerminal(string $state): bool
    {
        self::assertValid($state);
        return in_array($state, self::TERMINAL, true);
    }
}
