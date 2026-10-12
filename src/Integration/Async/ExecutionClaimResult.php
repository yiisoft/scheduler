<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function strlen;

/** Explicit result of a fenced claim; rejection never grants actor authority. */
final class ExecutionClaimResult
{
    private function __construct(public readonly bool $accepted, public readonly ?ExecutionLedgerRecord $record, public readonly ?string $reason) {}

    public static function accepted(ExecutionLedgerRecord $record): self
    {
        return new self(true, $record, null);
    }

    public static function rejected(string $reason): self
    {
        if ($reason === '' || strlen($reason) > 4096) {
            throw new InvalidArgumentException('Execution claim rejection reason is empty or too long.');
        }

        return new self(false, null, $reason);
    }
}
