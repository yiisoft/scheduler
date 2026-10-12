<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function preg_match;
use function strlen;

/** A bounded, non-sensitive fact about whether a launched process is closed. */
final class ExecutionProcessClosure
{
    private const UNKNOWN = 'unknown';
    private const CONFIRMED = 'confirmed';
    private const MAX_REASON_LENGTH = 4096;

    private function __construct(
        public readonly string $status,
        public readonly ?int $exitCode,
        public readonly string $reason,
    ) {}

    public static function unknown(string $reason): self
    {
        self::assertReason($reason);
        return new self(self::UNKNOWN, null, $reason);
    }

    public static function confirmed(?int $exitCode, string $reason): self
    {
        self::assertReason($reason);
        if ($exitCode !== null && ($exitCode < 0 || $exitCode > 255)) {
            throw new InvalidArgumentException('Process closure exit code is invalid.');
        }
        return new self(self::CONFIRMED, $exitCode, $reason);
    }

    public function isUnknown(): bool
    {
        return $this->status === self::UNKNOWN;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::CONFIRMED;
    }

    private static function assertReason(string $reason): void
    {
        if ($reason === '' || strlen($reason) > self::MAX_REASON_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $reason) === 1) {
            throw new InvalidArgumentException('Process closure reason is invalid.');
        }
    }
}
