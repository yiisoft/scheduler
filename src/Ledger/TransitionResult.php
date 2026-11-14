<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

/**
 * What the ledger did with a mutation, and the record as it stands afterwards.
 *
 * A refused mutation is an ordinary answer rather than an exception: another actor getting
 * there first is how a shared ledger is supposed to behave, and the caller needs the record to
 * decide what to do next. An exception is for a ledger that could not be read or written at
 * all, where nothing is known either way.
 */
final class TransitionResult
{
    private function __construct(
        public readonly TransitionStatus $status,
        public readonly ?ExecutionRecord $record,
    ) {}

    public static function accepted(ExecutionRecord $record): self
    {
        return new self(TransitionStatus::Accepted, $record);
    }

    public static function staleAuthority(ExecutionRecord $record): self
    {
        return new self(TransitionStatus::StaleAuthority, $record);
    }

    public static function alreadyTerminal(ExecutionRecord $record): self
    {
        return new self(TransitionStatus::AlreadyTerminal, $record);
    }

    public static function phaseNotAllowed(ExecutionRecord $record): self
    {
        return new self(TransitionStatus::PhaseNotAllowed, $record);
    }

    public static function missing(): self
    {
        return new self(TransitionStatus::Missing, null);
    }

    public function isAccepted(): bool
    {
        return TransitionStatus::Accepted === $this->status;
    }
}
