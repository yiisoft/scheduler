<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function strlen;

final class ExecutionRevocationResult
{
    public const ACCEPTED = 'accepted';
    public const ALREADY_REVOKED = 'already-revoked';
    public const STALE = 'stale';
    public const REJECTED = 'rejected';

    private function __construct(public readonly string $status, public readonly ?ExecutionLedgerRecord $record, public readonly ?string $reason)
    {
        if ($reason !== null && ($reason === '' || strlen($reason) > 4096)) {
            throw new InvalidArgumentException('Revocation reason is invalid.');
        }
    }

    public static function accepted(ExecutionLedgerRecord $record): self
    {
        return new self(self::ACCEPTED, $record, null);
    }

    public static function alreadyRevoked(ExecutionLedgerRecord $record): self
    {
        return new self(self::ALREADY_REVOKED, $record, null);
    }

    public static function stale(string $reason = 'stale recovery fence'): self
    {
        return new self(self::STALE, null, $reason);
    }

    public static function rejected(string $reason): self
    {
        return new self(self::REJECTED, null, $reason);
    }
}
