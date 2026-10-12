<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use InvalidArgumentException;

use function strlen;

final class ExecutionDispatchResult
{
    public const ACCEPTED = 'accepted';
    public const ALREADY_LAUNCHED = 'already-launched';
    public const AMBIGUOUS = 'ambiguous';
    public const STALE = 'stale';
    public const REJECTED = 'rejected';
    public const SAME_GENERATION_UNCLAIMED = 'same-generation-unclaimed';

    private function __construct(public readonly string $status, public readonly ?ExecutionLedgerRecord $record, public readonly ?string $reason)
    {
        if ($reason !== null && ($reason === '' || strlen($reason) > 4096)) {
            throw new InvalidArgumentException('Dispatch result reason is invalid.');
        }
    }

    public static function accepted(ExecutionLedgerRecord $record): self
    {
        return new self(self::ACCEPTED, $record, null);
    }

    public static function alreadyLaunched(ExecutionLedgerRecord $record): self
    {
        return new self(self::ALREADY_LAUNCHED, $record, null);
    }

    public static function ambiguous(ExecutionLedgerRecord $record, ?string $reason = null): self
    {
        return new self(self::AMBIGUOUS, $record, $reason);
    }

    public static function stale(?string $reason = 'stale dispatch'): self
    {
        return new self(self::STALE, null, $reason);
    }

    public static function rejected(string $reason): self
    {
        return new self(self::REJECTED, null, $reason);
    }

    public static function sameGenerationUnclaimed(ExecutionLedgerRecord $record): self
    {
        return new self(self::SAME_GENERATION_UNCLAIMED, $record, null);
    }
}
