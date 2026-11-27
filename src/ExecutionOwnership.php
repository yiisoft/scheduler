<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

use DateTimeImmutable;
use Yiisoft\Scheduler\Ledger\ExecutionAuthority;
use Yiisoft\Scheduler\Ledger\ExecutionIdentity;
use Yiisoft\Scheduler\Ledger\ExecutionOutcome;
use Yiisoft\Scheduler\Ledger\LaunchDescription;
use Yiisoft\Scheduler\Ledger\LaunchFact;
use Yiisoft\Scheduler\Ledger\ProcessObservation;

use function gethostname;

/**
 * The planner's side of an execution's record: taking the right to launch it, saying what was
 * launched, and giving the process back once there is nothing left of it.
 *
 * This is what makes the executions of a source visible to the admission of the next one, in
 * this planner and in any other reading the same ledger. A schedule without a ledger has
 * nothing to write to, and every method here does nothing for it.
 */
final class ExecutionOwnership
{
    public function __construct(private readonly Schedule $schedule) {}

    /**
     * Takes the right to launch the run, and returns the authority it was taken under.
     *
     * Null where there is no ledger to take it from, and where the record says the run is not
     * this planner's to launch: another planner claimed it first, or it is already finished.
     * The caller starts nothing in that case.
     */
    public function claim(TaskContext $context, DateTimeImmutable $at): ?ExecutionAuthority
    {
        $ledger = $this->schedule->getLedger();

        if (null === $ledger) {
            return null;
        }

        $identity = self::identityOf($context);
        $authority = $this->authorityOf($identity);
        $result = $ledger->claim($identity, $authority, $at);

        return $result->isAccepted() ? $authority : null;
    }

    /**
     * Records that a process was started for the run, and which one it is.
     */
    public function launched(TaskContext $context, ExecutionAuthority $authority, int $pid, DateTimeImmutable $at): void
    {
        $this->schedule->getLedger()?->recordLaunch(
            self::identityOf($context),
            $authority,
            LaunchFact::Started,
            new LaunchDescription(self::host(), $pid),
            $at,
        );
    }

    /**
     * Records that nothing was started for the run after all, so that the next pass does not
     * have to find out from a process that never existed.
     */
    public function notLaunched(TaskContext $context, ExecutionAuthority $authority, DateTimeImmutable $at): void
    {
        $this->schedule->getLedger()?->recordLaunch(
            self::identityOf($context),
            $authority,
            LaunchFact::ProvenNotStarted,
            null,
            $at,
        );
    }

    /**
     * Records what the run came to and gives its process back, in that order: an outcome
     * written after the process was released would be an outcome nobody was holding.
     *
     * Giving the process back is what returns the capacity it occupied, which is why it waits
     * for the process to be gone rather than for the planner to stop caring about it.
     */
    public function finished(
        TaskContext $context,
        ExecutionAuthority $authority,
        TaskStatus $status,
        ?ProcessObservation $observation,
        DateTimeImmutable $at,
    ): void {
        $ledger = $this->schedule->getLedger();

        if (null === $ledger) {
            return;
        }

        $identity = self::identityOf($context);
        $ledger->recordOutcome($identity, $authority, self::outcomeOf($status), $at);
        $ledger->releaseProcess($identity, $authority, $observation, $at);
    }

    public static function identityOf(TaskContext $context): ExecutionIdentity
    {
        return new ExecutionIdentity($context->scheduleName, $context->taskId, $context->triggeredAt);
    }

    /**
     * The authority the record carries now, which is the first attempt's unless recovery has
     * put the execution back for another one.
     */
    private function authorityOf(ExecutionIdentity $identity): ExecutionAuthority
    {
        $record = $this->schedule->getLedgerQueries()?->find($identity);

        return $record?->authority ?? ExecutionAuthority::forAttempt($identity, 1);
    }

    private static function outcomeOf(TaskStatus $status): ExecutionOutcome
    {
        return match ($status) {
            TaskStatus::Done => ExecutionOutcome::Done,
            TaskStatus::Skipped => ExecutionOutcome::Skipped,
            TaskStatus::Cancelled => ExecutionOutcome::Cancelled,
            default => ExecutionOutcome::Failed,
        };
    }

    private static function host(): string
    {
        $host = gethostname();

        return false === $host ? 'unknown' : $host;
    }
}
