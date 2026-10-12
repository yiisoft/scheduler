<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use InvalidArgumentException;

use function in_array;
use function strlen;

final class ExecutionRecoveryResult
{
    public const TERMINAL = 'terminal';
    public const SAME_GENERATION_UNCLAIMED = 'same-generation-unclaimed';
    public const LEASE_EXPIRED_OBSERVED = 'lease-expired-observed';
    public const AMBIGUOUS = 'ambiguous';
    public const NEEDS_RESOLUTION = 'needs-resolution';
    public const REVOKED = 'revoked';
    public const STALE = 'stale';
    public const REJECTED = 'rejected';

    private function __construct(public readonly string $status, public readonly ?ExecutionLedgerRecord $record, public readonly ?string $reason)
    {
        if ($reason !== null && ($reason === '' || strlen($reason) > 4096)) {
            throw new InvalidArgumentException('Recovery reason is invalid.');
        }
    }

    public static function terminal(ExecutionLedgerRecord $record): self
    {
        return new self(self::TERMINAL, $record, null);
    }

    public static function sameGenerationUnclaimed(ExecutionLedgerRecord $record): self
    {
        return new self(self::SAME_GENERATION_UNCLAIMED, $record, null);
    }

    public static function leaseExpiredObserved(ExecutionLedgerRecord $record): self
    {
        return new self(self::LEASE_EXPIRED_OBSERVED, $record, null);
    }

    public static function ambiguous(ExecutionLedgerRecord $record): self
    {
        return new self(self::AMBIGUOUS, $record, null);
    }

    public static function needsResolution(ExecutionLedgerRecord $record): self
    {
        return new self(self::NEEDS_RESOLUTION, $record, null);
    }

    public static function result(string $status, ?ExecutionLedgerRecord $record = null, ?string $reason = null): self
    {
        if (!in_array($status, [self::TERMINAL, self::SAME_GENERATION_UNCLAIMED, self::LEASE_EXPIRED_OBSERVED, self::AMBIGUOUS, self::NEEDS_RESOLUTION, self::REVOKED, self::STALE, self::REJECTED], true)) {
            throw new InvalidArgumentException('Invalid recovery result.');
        }
        return new self($status, $record, $reason);
    }
}
