<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use InvalidArgumentException;
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchDescriptor;

use function in_array;

/** Immutable, provider-independent evidence about one complete process scope. */
final class ProcessScopeProof
{
    public const LIVE = 'live';
    public const CLOSED_ESRCH = 'closed-esrch';
    public const CLOSED_TERMINAL = 'closed-terminal';
    public const UNCERTAIN = 'uncertain';

    public const LOOKUP = 'lookup';
    public const RETAINED_POLL = 'retained-poll';

    public const REASON_LIVE = 'live';
    public const REASON_GROUP_ABSENT_ESRCH = 'group-absent-esrch';
    public const REASON_TERMINAL_ONLY = 'terminal-only';
    public const REASON_EPERM = 'eperm';
    public const REASON_UNREADABLE = 'unreadable';
    public const REASON_RACE = 'race';
    public const REASON_IDENTITY_CONFLICT = 'identity-conflict';
    public const REASON_GROUP_REUSE = 'group-reuse';
    public const REASON_MISSING_LEADER_LIVE_MEMBER = 'missing-leader-live-member';
    public const REASON_UNSUPPORTED = 'unsupported';
    public const REASON_UNBOUND_SCOPE = 'unbound-scope';

    private const UNCERTAIN_REASONS = [
        self::REASON_EPERM,
        self::REASON_UNREADABLE,
        self::REASON_RACE,
        self::REASON_IDENTITY_CONFLICT,
        self::REASON_GROUP_REUSE,
        self::REASON_MISSING_LEADER_LIVE_MEMBER,
        self::REASON_UNSUPPORTED,
        self::REASON_UNBOUND_SCOPE,
    ];

    private function __construct(
        public readonly string $status,
        public readonly ExecutionLaunchDescriptor $descriptor,
        public readonly string $provenance,
        public readonly string $reason,
        public readonly ?ProcessIdentity $identity,
    ) {
        if (!in_array($provenance, [self::LOOKUP, self::RETAINED_POLL], true)) {
            throw new InvalidArgumentException('Invalid process scope proof provenance.');
        }
        if ($status === self::LIVE && ($identity === null || $reason !== self::REASON_LIVE)) {
            throw new InvalidArgumentException('A live proof requires live identity facts.');
        }
        if ($status === self::CLOSED_ESRCH && ($identity === null || $reason !== self::REASON_GROUP_ABSENT_ESRCH)) {
            throw new InvalidArgumentException('An ESRCH proof requires its kernel absence reason and identity facts.');
        }
        if ($status === self::CLOSED_TERMINAL && ($identity === null || $reason !== self::REASON_TERMINAL_ONLY)) {
            throw new InvalidArgumentException('A terminal proof requires its terminal-only reason and identity facts.');
        }
        if ($status === self::UNCERTAIN && !in_array($reason, self::UNCERTAIN_REASONS, true)) {
            throw new InvalidArgumentException('An uncertain proof requires a bounded uncertainty reason.');
        }
        if (!in_array($status, [self::LIVE, self::CLOSED_ESRCH, self::CLOSED_TERMINAL, self::UNCERTAIN], true)) {
            throw new InvalidArgumentException('Invalid process scope proof status.');
        }
        if ($identity !== null && !$descriptor->equals($identity->descriptor)) {
            throw new InvalidArgumentException('Process scope proof identity is bound to another descriptor.');
        }
    }

    public static function liveFromLookup(ExecutionLaunchDescriptor $descriptor, ProcessIdentity $identity): self
    {
        return new self(self::LIVE, $descriptor, self::LOOKUP, self::REASON_LIVE, $identity);
    }

    public static function liveFromRetainedPoll(ExecutionLaunchDescriptor $descriptor, ProcessIdentity $identity): self
    {
        return new self(self::LIVE, $descriptor, self::RETAINED_POLL, self::REASON_LIVE, $identity);
    }

    public static function closedEsrchFromLookup(ExecutionLaunchDescriptor $descriptor, ProcessIdentity $identity): self
    {
        return new self(self::CLOSED_ESRCH, $descriptor, self::LOOKUP, self::REASON_GROUP_ABSENT_ESRCH, $identity);
    }

    public static function closedTerminalFromLookup(ExecutionLaunchDescriptor $descriptor, ProcessIdentity $identity): self
    {
        return new self(self::CLOSED_TERMINAL, $descriptor, self::LOOKUP, self::REASON_TERMINAL_ONLY, $identity);
    }

    public static function closedEsrchFromRetainedPoll(ExecutionLaunchDescriptor $descriptor, ProcessIdentity $identity): self
    {
        return new self(self::CLOSED_ESRCH, $descriptor, self::RETAINED_POLL, self::REASON_GROUP_ABSENT_ESRCH, $identity);
    }

    public static function closedTerminalFromRetainedPoll(ExecutionLaunchDescriptor $descriptor, ProcessIdentity $identity): self
    {
        return new self(self::CLOSED_TERMINAL, $descriptor, self::RETAINED_POLL, self::REASON_TERMINAL_ONLY, $identity);
    }

    public static function uncertainFromLookup(ExecutionLaunchDescriptor $descriptor, string $reason, ?ProcessIdentity $identity = null): self
    {
        return self::uncertain($descriptor, self::LOOKUP, $reason, $identity);
    }

    public static function uncertainFromRetainedPoll(ExecutionLaunchDescriptor $descriptor, string $reason, ?ProcessIdentity $identity = null): self
    {
        return self::uncertain($descriptor, self::RETAINED_POLL, $reason, $identity);
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED_ESRCH || $this->status === self::CLOSED_TERMINAL;
    }

    private static function uncertain(
        ExecutionLaunchDescriptor $descriptor,
        string $provenance,
        string $reason,
        ?ProcessIdentity $identity = null,
    ): self {
        return new self(self::UNCERTAIN, $descriptor, $provenance, $reason, $identity);
    }
}
