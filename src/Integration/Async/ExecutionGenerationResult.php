<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function strlen;

final class ExecutionGenerationResult
{
    public const ALLOCATED = 'allocated';
    public const REQUIRES_REVOCATION = 'requires-revocation';
    public const STALE = 'stale';
    public const REJECTED = 'rejected';

    private function __construct(public readonly string $status, public readonly ?ExecutionFence $fence, public readonly ?ExecutionLedgerRecord $record, public readonly ?string $reason)
    {
        if ($reason !== null && ($reason === '' || strlen($reason) > 4096)) {
            throw new InvalidArgumentException('Generation result reason is invalid.');
        }
    }

    public static function allocated(ExecutionFence $fence, ExecutionLedgerRecord $record): self
    {
        return new self(self::ALLOCATED, $fence, $record, null);
    }

    public static function requiresRevocation(ExecutionLedgerRecord $record): self
    {
        return new self(self::REQUIRES_REVOCATION, null, $record, 'explicit revocation required');
    }

    public static function stale(string $reason = 'stale recovery fence'): self
    {
        return new self(self::STALE, null, null, $reason);
    }

    public static function rejected(string $reason): self
    {
        return new self(self::REJECTED, null, null, $reason);
    }
}
