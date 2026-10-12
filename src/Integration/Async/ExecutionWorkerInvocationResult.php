<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use InvalidArgumentException;

use function in_array;
use function strlen;

/** Bounded application-facing result of one worker invocation. */
final class ExecutionWorkerInvocationResult
{
    public const COMMITTED_SUCCEEDED = 'committed-succeeded';
    public const COMMITTED_FAILED = 'committed-failed';
    public const COMMITTED_CANCELLED = 'committed-cancelled';
    public const INVALID_CAPABILITY = 'invalid-capability';
    public const LOCATOR_UNAVAILABLE = 'locator-unavailable';
    public const CLAIM_REJECTED = 'claim-rejected';
    public const POST_CLAIM_FAILED = 'post-claim-failed';
    public const UNRESOLVED_OWNERSHIP = 'unresolved-ownership';
    public const TERMINAL_MISSING = 'terminal-missing';
    public const TERMINAL_STALE_FENCED = 'terminal-stale-fenced';
    public const TERMINAL_ALREADY_TERMINAL = 'terminal-already-terminal';
    public const TERMINAL_REJECTED = 'terminal-rejected';
    public const TERMINAL_AMBIGUOUS = 'terminal-ambiguous';

    public function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly ?ExecutionOutcome $outcome = null,
    ) {
        $statuses = [self::COMMITTED_SUCCEEDED, self::COMMITTED_FAILED, self::COMMITTED_CANCELLED, self::INVALID_CAPABILITY, self::LOCATOR_UNAVAILABLE, self::CLAIM_REJECTED, self::POST_CLAIM_FAILED, self::UNRESOLVED_OWNERSHIP, self::TERMINAL_MISSING, self::TERMINAL_STALE_FENCED, self::TERMINAL_ALREADY_TERMINAL, self::TERMINAL_REJECTED, self::TERMINAL_AMBIGUOUS];
        if (!in_array($status, $statuses, true) || ($reason !== null && ($reason === '' || strlen($reason) > 4096))) {
            throw new InvalidArgumentException('Invalid worker invocation result.');
        }
    }
}
