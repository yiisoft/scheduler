<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Ledger;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Ledger\ExecutionAuthority;
use Yiisoft\Scheduler\Ledger\ExecutionIdentity;
use Yiisoft\Scheduler\Ledger\ExecutionOutcome;
use Yiisoft\Scheduler\Ledger\ExecutionPhase;
use Yiisoft\Scheduler\Ledger\LaunchDescription;
use Yiisoft\Scheduler\Ledger\LaunchFact;
use Yiisoft\Scheduler\Ledger\LedgerCommandInterface;
use Yiisoft\Scheduler\Ledger\LedgerQueryInterface;
use Yiisoft\Scheduler\Ledger\ProcessObservation;
use Yiisoft\Scheduler\Ledger\TransitionStatus;

use function sprintf;

/**
 * What a ledger has to do, whatever it keeps its records in.
 *
 * An implementation proves itself by extending this and returning its own ledger. Nothing here
 * touches a database, a file or a clock: the cases are about the transitions, so that a store
 * which passes them behaves the same way as every other one the package planner can be pointed
 * at.
 *
 * These are the crash windows and the races spelled out as tests. Each one stands for something
 * that goes wrong in production when a store gets it wrong: work admitted twice, an old worker
 * overwriting the attempt that replaced it, a process exit erasing what the task itself
 * reported, a recovery pass that never reaches the end of its backlog.
 */
abstract class LedgerContractTestCase extends TestCase
{
    public function testAdmittingOneOccurrenceTwiceIsOneExecution(): void
    {
        // The crash window this exists for: a planner interrupted between admitting an
        // occurrence and acknowledging it comes back and admits it again.
        $ledger = $this->ledger();
        $identity = $this->identity();

        $first = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $second = $ledger->admit($identity, 'rev-1', $this->at('+1 minute'), $this->at('+2 hours'));

        $this->assertSame($first->authority->token, $second->authority->token);
        $this->assertEquals($first->admittedAt, $second->admittedAt);
        $this->assertCount(1, $ledger->unresolved($identity->source, 10));
    }

    public function testAdmittingAgainDoesNotUndoWhatHappenedSince(): void
    {
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->claim($identity, $record->authority, $this->at());

        $again = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));

        $this->assertSame(ExecutionPhase::Claimed, $again->phase);
    }

    public function testAStaleAuthorityChangesNothing(): void
    {
        // The old worker, still running, still believing it owns the execution.
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->reauthorize($identity, $record->authority, new ExecutionAuthority('second'), $this->at());

        $result = $ledger->recordOutcome($identity, $record->authority, ExecutionOutcome::Done, $this->at());

        $this->assertSame(TransitionStatus::StaleAuthority, $result->status);
        $this->assertNull($ledger->find($identity)?->outcome);
    }

    public function testTheFirstOutcomeStands(): void
    {
        // A process seen to exit afterwards has learned nothing the work had not already said.
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->recordOutcome($identity, $record->authority, ExecutionOutcome::Done, $this->at());

        $result = $ledger->recordOutcome($identity, $record->authority, ExecutionOutcome::Failed, $this->at('+1 minute'));

        $this->assertSame(TransitionStatus::AlreadyTerminal, $result->status);
        $this->assertSame(ExecutionOutcome::Done, $ledger->find($identity)?->outcome);
    }

    public function testAnOutcomeDoesNotGiveUpTheProcess(): void
    {
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->recordLaunch($identity, $record->authority, LaunchFact::Started, $this->process(), $this->at());
        $ledger->recordOutcome($identity, $record->authority, ExecutionOutcome::Done, $this->at('+1 minute'));

        $after = $ledger->find($identity);

        $this->assertNotNull($after);
        $this->assertTrue($after->isTerminal());
        $this->assertTrue($after->holdsProcess(), 'a finished execution can still own the process that ran it');
        $this->assertCount(1, $ledger->holdingProcess($identity->source, 10));
    }

    public function testGivingUpTheProcessWorksAfterAnOutcome(): void
    {
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->recordLaunch($identity, $record->authority, LaunchFact::Started, $this->process(), $this->at());
        $ledger->recordOutcome($identity, $record->authority, ExecutionOutcome::Done, $this->at());

        $result = $ledger->releaseProcess($identity, $record->authority, new ProcessObservation(0), $this->at('+1 minute'));

        $this->assertTrue($result->isAccepted());
        $this->assertSame([], $ledger->holdingProcess($identity->source, 10));
        $this->assertSame([], $ledger->unresolved($identity->source, 10));
    }

    public function testAnUnknownLaunchStaysEligibleForRecovery(): void
    {
        // The one that must not be read as either of its neighbours: the work may be running.
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->recordLaunch($identity, $record->authority, LaunchFact::Unknown, null, $this->at());

        $found = $ledger->unresolved($identity->source, 10);

        $this->assertCount(1, $found);
        $this->assertSame(LaunchFact::Unknown, $found[0]->launch);
    }

    public function testAProvenNonStartIsNotADuplicateRiskWhileAnUnknownLaunchIs(): void
    {
        $ledger = $this->ledger();

        $clean = $this->identity(taskId: 'clean');
        $cleanRecord = $ledger->admit($clean, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->recordLaunch($clean, $cleanRecord->authority, LaunchFact::ProvenNotStarted, null, $this->at());
        $ledger->reauthorize($clean, $cleanRecord->authority, new ExecutionAuthority('clean-2'), $this->at());

        $risky = $this->identity(taskId: 'risky');
        $riskyRecord = $ledger->admit($risky, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->recordLaunch($risky, $riskyRecord->authority, LaunchFact::Unknown, null, $this->at());
        $ledger->reauthorize($risky, $riskyRecord->authority, new ExecutionAuthority('risky-2'), $this->at());

        $this->assertFalse($ledger->find($clean)?->mayHaveRunBefore);
        $this->assertTrue($ledger->find($risky)?->mayHaveRunBefore);
    }

    public function testADuplicateRiskIsNeverTakenBackOff(): void
    {
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->recordLaunch($identity, $record->authority, LaunchFact::Unknown, null, $this->at());

        $second = new ExecutionAuthority('second');
        $ledger->reauthorize($identity, $record->authority, $second, $this->at());
        $ledger->recordLaunch($identity, $second, LaunchFact::ProvenNotStarted, null, $this->at());
        $ledger->reauthorize($identity, $second, new ExecutionAuthority('third'), $this->at());

        $this->assertTrue($ledger->find($identity)?->mayHaveRunBefore);
    }

    public function testAnotherAttemptKeepsTheRevisionItWasAdmittedUnder(): void
    {
        // Recovery runs work under the configuration that admitted it or not at all.
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $deadline = $record->recoveryDeadline;

        $ledger->reauthorize($identity, $record->authority, new ExecutionAuthority('second'), $this->at('+1 minute'));
        $ledger->admit($identity, 'rev-2', $this->at('+2 minutes'), $this->at('+9 hours'));

        $after = $ledger->find($identity);

        $this->assertSame('rev-1', $after?->sourceRevision);
        $this->assertEquals($deadline, $after?->recoveryDeadline);
    }

    public function testThereIsNothingLeftToAttemptOnceAnOutcomeIsRecorded(): void
    {
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->recordOutcome($identity, $record->authority, ExecutionOutcome::Done, $this->at());

        $result = $ledger->reauthorize($identity, $record->authority, new ExecutionAuthority('second'), $this->at());

        $this->assertSame(TransitionStatus::AlreadyTerminal, $result->status);
    }

    public function testAnExecutionThatWasNeverAdmittedCannotBeChanged(): void
    {
        $ledger = $this->ledger();
        $identity = $this->identity();

        $result = $ledger->claim($identity, new ExecutionAuthority('made up'), $this->at());

        $this->assertSame(TransitionStatus::Missing, $result->status);
        $this->assertNull($result->record);
    }

    public function testQueriesAreScopedToOneSource(): void
    {
        $ledger = $this->ledger();

        $mine = $this->identity(source: 'mine');
        $theirs = $this->identity(source: 'theirs');

        $ledger->admit($mine, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->admit($theirs, 'rev-1', $this->at(), $this->at('+1 hour'));

        $found = $ledger->unresolved('mine', 10);

        $this->assertCount(1, $found);
        $this->assertSame('mine', $found[0]->identity->source);
    }

    public function testABacklogIsWalkedToItsEndInPagesOfOne(): void
    {
        // The fairness case: a recovery pass has to reach every record, not the first page of
        // them over and over.
        $ledger = $this->ledger();
        $admitted = [];

        for ($minute = 0; $minute < 5; ++$minute) {
            $identity = $this->identity(occurrence: $this->at(sprintf('+%d minutes', $minute)));
            $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
            $admitted[] = $identity->occurrence->format('H:i');
        }

        $walked = [];
        $after = null;

        while ([] !== ($page = $ledger->unresolved('default', 1, $after))) {
            foreach ($page as $record) {
                $walked[] = $record->identity->occurrence->format('H:i');
                $after = $record->identity;
            }
        }

        $this->assertSame($admitted, $walked, 'in the order the occurrences happened, each one once');
    }

    public function testTwoTasksDueAtOneTimeAreBothReachedByPaging(): void
    {
        $ledger = $this->ledger();
        $occurrence = $this->at();

        foreach (['a', 'b'] as $taskId) {
            $ledger->admit($this->identity(taskId: $taskId, occurrence: $occurrence), 'rev-1', $this->at(), $this->at('+1 hour'));
        }

        $first = $ledger->unresolved('default', 1);
        $second = $ledger->unresolved('default', 1, $first[0]->identity);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertNotSame($first[0]->identity->taskId, $second[0]->identity->taskId);
    }

    public function testOneOfTwoPlannersClaimsAnAdmittedExecution(): void
    {
        // Both admitted it, so both hold the authority admission handed out and the authority
        // alone cannot tell them apart. The claim is what does.
        $ledger = $this->ledger();
        $identity = $this->identity();

        $mine = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $theirs = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));

        $first = $ledger->claim($identity, $mine->authority, $this->at());
        $second = $ledger->claim($identity, $theirs->authority, $this->at());

        $this->assertTrue($first->isAccepted());
        $this->assertSame(TransitionStatus::PhaseNotAllowed, $second->status);
    }

    public function testFinishedWorkCannotBeClaimedOrLaunchedAgain(): void
    {
        // Phase is what the terminal guard reads, so a claim accepted after an outcome would
        // move the record out of terminal and let the work be reauthorized and run again.
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->recordOutcome($identity, $record->authority, ExecutionOutcome::Done, $this->at());

        $claim = $ledger->claim($identity, $record->authority, $this->at('+1 minute'));
        $launch = $ledger->recordLaunch($identity, $record->authority, LaunchFact::Unknown, null, $this->at('+1 minute'));

        $this->assertSame(TransitionStatus::AlreadyTerminal, $claim->status);
        $this->assertSame(TransitionStatus::AlreadyTerminal, $launch->status);
        $this->assertTrue($ledger->find($identity)?->isTerminal());
    }

    public function testAnAttemptDoesNotLaunchOverAProcessTheRecordStillHolds(): void
    {
        // The process of the attempt before is the only description of something that may
        // still be running. Launching over it would lose it.
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->recordLaunch($identity, $record->authority, LaunchFact::Started, $this->process(), $this->at());

        $second = new ExecutionAuthority('second');
        $ledger->reauthorize($identity, $record->authority, $second, $this->at('+1 minute'));

        $again = $ledger->recordLaunch($identity, $second, LaunchFact::Started, $this->process(5151), $this->at('+2 minutes'));

        $this->assertSame(TransitionStatus::PhaseNotAllowed, $again->status);
        $this->assertSame(4242, $ledger->find($identity)?->process?->pid);
    }

    public function testAnotherAttemptKeepsTheProcessTheOneBeforeStarted(): void
    {
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->recordLaunch($identity, $record->authority, LaunchFact::Started, $this->process(), $this->at());

        $second = new ExecutionAuthority('second');
        $ledger->reauthorize($identity, $record->authority, $second, $this->at('+1 minute'));

        $after = $ledger->find($identity);

        $this->assertTrue($after?->holdsProcess(), 'replacing the authority does not stop the worker');
        $this->assertCount(1, $ledger->holdingProcess($identity->source, 10));

        $released = $ledger->releaseProcess($identity, $second, new ProcessObservation(null, 9), $this->at('+2 minutes'));

        $this->assertTrue($released->isAccepted());
        $this->assertSame([], $ledger->holdingProcess($identity->source, 10));
    }

    public function testWhatWasSeenOfAReleasedProcessIsKept(): void
    {
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->recordLaunch($identity, $record->authority, LaunchFact::Started, $this->process(), $this->at());
        $ledger->releaseProcess($identity, $record->authority, new ProcessObservation(137, 9), $this->at('+1 minute'));

        $after = $ledger->find($identity);

        $this->assertSame(137, $after?->processObservation?->exitCode);
        $this->assertSame(9, $after?->processObservation?->signal);
        $this->assertNull($after->outcome, 'an exit status is not what the work achieved');
    }

    abstract protected function ledger(): LedgerCommandInterface&LedgerQueryInterface;

    protected function identity(
        string $source = 'default',
        string $taskId = 'task',
        ?DateTimeImmutable $occurrence = null,
    ): ExecutionIdentity {
        return new ExecutionIdentity($source, $taskId, $occurrence ?? $this->at());
    }

    protected function at(string $modify = ''): DateTimeImmutable
    {
        $date = new DateTimeImmutable('2026-01-01 00:00:00+00:00');

        return '' === $modify ? $date : $date->modify($modify);
    }

    protected function process(int $pid = 4242): LaunchDescription
    {
        return new LaunchDescription('host', $pid, 'boot', '12345');
    }
}
