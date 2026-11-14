<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

use DateTimeImmutable;

/**
 * Everything the ledger durably knows about one admitted execution.
 *
 * Read-only: a record is produced by the ledger and never changed in place, so a caller holding
 * one holds what was true when it read it and has to go back for anything newer. Every mutation
 * returns the record as it stands afterwards, which is how a caller learns that what it believed
 * was already out of date.
 */
final class ExecutionRecord
{
    public function __construct(
        public readonly ExecutionIdentity $identity,
        /**
         * The revision of the configuration this occurrence was admitted under. Recovery runs
         * work under the revision that admitted it or does not run it at all, so this is not
         * replaced when the application is deployed again.
         */
        public readonly string $sourceRevision,
        public readonly ExecutionAuthority $authority,
        public readonly ExecutionPhase $phase,
        public readonly LaunchFact $launch,
        public readonly ?LaunchDescription $process,
        public readonly ?ExecutionOutcome $outcome,
        public readonly DateTimeImmutable $admittedAt,
        /**
         * When recovery stops starting this execution again. It bounds new attempts only:
         * a process already owned is still observed afterwards, because an expired deadline
         * says nothing about whether it stopped.
         */
        public readonly DateTimeImmutable $recoveryDeadline,
        /**
         * What was seen of the process this record last gave up, kept so that a backlog read
         * afterwards says on what evidence it was released. It is never read as an outcome.
         */
        public readonly ?ProcessObservation $processObservation = null,
        public readonly ?DateTimeImmutable $claimedAt = null,
        public readonly ?DateTimeImmutable $resolvedAt = null,
        /**
         * Whether an attempt may have run before this one. Set when an execution is authorised
         * again while its previous launch was never resolved, and never cleared: a duplicate
         * risk is a fact about the execution's history, not a state it leaves.
         */
        public readonly bool $mayHaveRunBefore = false,
    ) {}

    /** Whether a business outcome is recorded. Says nothing about the process. */
    public function isTerminal(): bool
    {
        return ExecutionPhase::Terminal === $this->phase;
    }

    /**
     * Whether a process launched for this execution is still this record's to account for.
     * True after a launch until ownership is explicitly released, including once an outcome is
     * recorded: work can report what it did and still hold the process that did it.
     */
    public function holdsProcess(): bool
    {
        return null !== $this->process;
    }

    /**
     * Whether recovery still has something to decide. A record with an outcome and no process
     * left is finished with; anything else is eligible, an unknown launch included.
     */
    public function needsRecovery(): bool
    {
        return !$this->isTerminal() || $this->holdsProcess();
    }
}
