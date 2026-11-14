<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

use DateTimeImmutable;

/**
 * Writing the durable facts of an execution, whatever they are kept in.
 *
 * Every method here is one atomic transition. An implementation that cannot make a transition
 * atomic cannot implement this: the whole point of the ledger is that a crash leaves the record
 * either as it was or as the transition meant it, and never half way.
 *
 * Every mutation but {@see admit()} names the authority the caller believes is current, and is
 * refused when it is not. Nothing here signals a process, decides whether to launch anything,
 * or interprets what a process did; those are decisions made elsewhere from what this records.
 */
interface LedgerCommandInterface
{
    /**
     * Writes the record for an occurrence, or returns the one already written for it.
     *
     * Idempotent by identity, because this is the handoff a crash lands in: a planner that was
     * interrupted between admitting an occurrence and acknowledging its scheduling progress
     * admits it again on the next pass, and must find what it wrote rather than create a second
     * execution of the same occurrence. The record it finds keeps its own authority and phase;
     * admitting does not reset an execution that is already under way.
     *
     * Nothing may acknowledge scheduling progress past an occurrence until this has returned.
     */
    public function admit(
        ExecutionIdentity $identity,
        string $sourceRevision,
        DateTimeImmutable $at,
        DateTimeImmutable $recoveryDeadline,
    ): ExecutionRecord;

    /**
     * Takes the right to launch an admitted execution.
     *
     * Accepted once. Two planners that admitted the same occurrence hold the same authority,
     * because admission hands out one, so the authority alone cannot tell them apart: the
     * second claim is refused because the record has already been claimed, and that is how
     * two planners reaching one occurrence resolve. The loser is told so and leaves it alone.
     *
     * A later attempt claims again, because {@see reauthorize()} puts the record back where a
     * claim is allowed from.
     */
    public function claim(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        DateTimeImmutable $at,
    ): TransitionResult;

    /**
     * Records what became of the attempt to start a process.
     *
     * A description belongs with {@see LaunchFact::Started} and with nothing else: a launch that
     * is proven not to have started has no process to describe, and one whose result is unknown
     * has none that is known to exist.
     *
     * Only by an attempt that has claimed the execution: a claim is the right to launch, and
     * an execution launched without taking it is the race the claim exists to settle.
     *
     * Once per attempt, and not while the record still holds a process. A second report would
     * replace the first, and a launch recorded over an owned process would lose the only
     * description of something that may still be running. An attempt that finds a process still
     * owned observes it and releases it before it launches anything of its own.
     */
    public function recordLaunch(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        LaunchFact $launch,
        ?LaunchDescription $process,
        DateTimeImmutable $at,
    ): TransitionResult;

    /**
     * Records what the work came to.
     *
     * The first outcome written stands. A later one is refused rather than applied, because the
     * thing that reports an outcome knows more about the work than anything observing it from
     * outside does, and a process seen to exit afterwards has not learned anything newer.
     *
     * Ownership of the process is untouched: an execution can report what it did and still hold
     * the process that did it.
     */
    public function recordOutcome(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        ExecutionOutcome $outcome,
        DateTimeImmutable $at,
    ): TransitionResult;

    /**
     * Gives up the process this execution was holding, once it is known to be closed.
     *
     * Separate from the outcome because the two are learned separately and often in the other
     * order. Until this is called the record counts against whatever capacity is reckoned from
     * owned processes, which is the honest answer while a process may still be running.
     *
     * The observation is what was seen of the process, and it is kept with the release it
     * explains. It is evidence that something stopped, never evidence of what it achieved: an
     * exit status of zero is not an outcome, and a record whose process is released with one
     * stays without an outcome until the work reports its own.
     *
     * Refused when the record holds no process, so that a release cannot write an observation
     * over the one belonging to the process it was actually taken from.
     */
    public function releaseProcess(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        ?ProcessObservation $observation,
        DateTimeImmutable $at,
    ): TransitionResult;

    /**
     * Hands the execution to a new attempt under a new authority, so the old one can no longer
     * write to it.
     *
     * The admitted revision, the recovery deadline and the record's history are kept: a second
     * attempt is the same admitted work, not a new occurrence. When the previous attempt had
     * launched or may have launched, the record is marked as one that may have run before, and
     * that mark is never taken off.
     *
     * A process the record holds is kept too. Replacing the authority does not stop the worker
     * that was started under the old one, and a record that forgot it would stop counting
     * against capacity and leave nothing for recovery to observe. The new attempt releases it
     * once it is known to be closed, and only then may it launch anything itself.
     *
     * Refused once an outcome is recorded: there is nothing left to attempt. `$next` must be an
     * authority this execution is not already under, which is the caller's to arrange.
     */
    public function reauthorize(
        ExecutionIdentity $identity,
        ExecutionAuthority $expected,
        ExecutionAuthority $next,
        DateTimeImmutable $at,
    ): TransitionResult;
}
