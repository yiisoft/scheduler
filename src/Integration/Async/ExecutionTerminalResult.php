<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function strlen;

/** Explicit result of a fenced terminal write. */
final class ExecutionTerminalResult
{
    public const ACCEPTED = 'accepted';
    public const MISSING = 'missing';
    public const STALE_FENCED = 'stale-fenced';
    public const ALREADY_TERMINAL = 'already-terminal';
    public const REJECTED = 'rejected';

    private function __construct(
        public readonly string $status,
        public readonly ?ExecutionLedgerRecord $record,
        public readonly ?string $reason,
    ) {}

    public static function accepted(ExecutionLedgerRecord $record): self
    {
        return new self(self::ACCEPTED, $record, null);
    }

    public static function missing(): self
    {
        return new self(self::MISSING, null, null);
    }

    public static function staleFenced(): self
    {
        return new self(self::STALE_FENCED, null, null);
    }

    public static function alreadyTerminal(): self
    {
        return new self(self::ALREADY_TERMINAL, null, null);
    }

    public static function rejected(string $reason): self
    {
        if ($reason === '' || strlen($reason) > 4096) {
            throw new InvalidArgumentException('Execution terminal rejection reason is empty or too long.');
        }

        return new self(self::REJECTED, null, $reason);
    }
}
