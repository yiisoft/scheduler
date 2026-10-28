<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use InvalidArgumentException;

use function in_array;

/** Explicitly distinguishes proof of absence from an observation failure. */
final class ProcessLookupResult
{
    public const MATCH = 'matching-live-process';
    public const NO_MATCH = 'completed-no-match';
    public const CLOSED = 'closed';
    public const BLOCKED = 'blocked';
    public const AMBIGUOUS = 'ambiguous';
    public const UNREADABLE = 'unreadable';
    public const MISMATCHED = 'mismatched';
    public const UNSUPPORTED = 'unsupported';

    public function __construct(
        public readonly string $status,
        public readonly ?ProcessIdentity $identity = null,
        public readonly ?ProcessHandleInterface $handle = null,
        public readonly ?ProcessScopeProof $proof = null,
    ) {
        if (!in_array($status, [self::MATCH, self::NO_MATCH, self::CLOSED, self::BLOCKED, self::AMBIGUOUS, self::UNREADABLE, self::MISMATCHED, self::UNSUPPORTED], true)) {
            throw new InvalidArgumentException('Invalid process lookup status.');
        }
        if ($status === self::MATCH && ($identity === null || $handle === null)) {
            throw new InvalidArgumentException('A match requires verified identity and attached handle.');
        }
        if ($status !== self::MATCH && ($identity !== null || $handle !== null)) {
            throw new InvalidArgumentException('Only a match may carry process facts.');
        }
        if ($status === self::NO_MATCH && $proof !== null) {
            throw new InvalidArgumentException('A no-match result cannot carry scope closure proof.');
        }
        if ($status === self::CLOSED && ($proof === null || !$proof->isClosed())) {
            throw new InvalidArgumentException('A closed result requires closed scope proof.');
        }
        if ($status === self::MATCH && $proof !== null) {
            if ($proof->status !== ProcessScopeProof::LIVE || !$proof->descriptor->equals($identity->descriptor) || $proof->identity === null || !$proof->identity->sameFacts($identity)) {
                throw new InvalidArgumentException('A matching result proof does not match process facts.');
            }
        }
        if ($status !== self::MATCH && $status !== self::CLOSED && $proof !== null && $proof->status !== ProcessScopeProof::UNCERTAIN) {
            throw new InvalidArgumentException('Only uncertain proof may accompany an uncertain lookup result.');
        }
    }

    public function matched(): bool
    {
        return $this->status === self::MATCH;
    }

    public function completedNoMatch(): bool
    {
        return $this->status === self::NO_MATCH;
    }
}
