<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Ledger;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Yiisoft\Db\Sqlite\Connection;
use Yiisoft\Db\Sqlite\Driver;
use Yiisoft\Scheduler\Ledger\DbLedger;
use Yiisoft\Scheduler\Ledger\ExecutionIdentity;
use Yiisoft\Scheduler\Ledger\ExecutionOutcome;
use Yiisoft\Scheduler\Ledger\LaunchFact;
use Yiisoft\Scheduler\Ledger\LedgerCommandInterface;
use Yiisoft\Scheduler\Ledger\LedgerQueryInterface;
use Yiisoft\Scheduler\Ledger\ProcessObservation;
use Yiisoft\Scheduler\Ledger\TransitionStatus;

use function str_repeat;
use function str_replace;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The default adapter against the same contract, on the database it is the default for.
 *
 * A file rather than `:memory:`, because reopening the store is half of what it is for and an
 * in-memory database is gone the moment its connection is.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class DbLedgerTest extends LedgerContractTestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if (null !== $this->file) {
            @unlink($this->file);
            $this->file = null;
        }

        parent::tearDown();
    }

    public function testRecordsSurviveTheConnectionThatWroteThem(): void
    {
        $identity = $this->identity();

        $ledger = $this->ledger();
        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->claim($identity, $record->authority, $this->at());
        $ledger->recordLaunch($identity, $record->authority, LaunchFact::Started, $this->process(), $this->at());

        $reopened = $this->open();
        $found = $reopened->find($identity);

        $this->assertNotNull($found);
        $this->assertSame($record->authority->token, $found->authority->token);
        $this->assertTrue($found->holdsProcess());
        $this->assertSame(4242, $found->process?->pid);
        $this->assertCount(1, $reopened->unresolved($identity->source, 10));
    }

    public function testTwoConnectionsAdmittingOneOccurrenceIsOneExecution(): void
    {
        // Two planners on two machines reaching the same due time, which is the race the
        // primary key is there for: the second admission finds the first one's record.
        $identity = $this->identity();

        $first = $this->ledger()->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $second = $this->open()->admit($identity, 'rev-2', $this->at('+1 minute'), $this->at('+9 hours'));

        $this->assertSame($first->authority->token, $second->authority->token);
        $this->assertSame('rev-1', $second->sourceRevision);
        $this->assertCount(1, $this->open()->unresolved($identity->source, 10));
    }

    public function testOnlyOneOfTwoConnectionsTakesTheExecutionFromUnderTheOther(): void
    {
        // Both read the same authority and both move to replace it. The condition is in the
        // `UPDATE`, so the database decides which one did, not whichever read last.
        $identity = $this->identity();

        $ledger = $this->ledger();
        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));

        $mine = $ledger->reauthorize($identity, $record->authority, $this->at());
        $theirs = $this->open()->reauthorize($identity, $record->authority, $this->at());

        $this->assertTrue($mine->isAccepted());
        $this->assertSame(TransitionStatus::StaleAuthority, $theirs->status);
        $this->assertSame(2, $this->open()->find($identity)?->attempt);
        $this->assertSame(
            $mine->record?->authority->token,
            $this->open()->find($identity)?->authority->token,
        );
    }

    public function testAnObservationOfNeitherAnExitCodeNorASignalIsStillAnObservation(): void
    {
        // The absence is the observation: something saw the process was gone without having
        // waited on it. Two empty columns cannot be how a record says there was none.
        $identity = $this->identity();

        $ledger = $this->ledger();
        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->claim($identity, $record->authority, $this->at());
        $ledger->recordLaunch($identity, $record->authority, LaunchFact::Started, $this->process(), $this->at());
        $ledger->releaseProcess($identity, $record->authority, new ProcessObservation(), $this->at());

        $found = $this->open()->find($identity);

        $this->assertNotNull($found?->processObservation);
        $this->assertNull($found->processObservation->exitCode);
        $this->assertNull($found->processObservation->signal);
    }

    public function testNamesLongerThanAColumnWouldHoldSurviveTheRoundTrip(): void
    {
        // A source, a task id and a revision are whatever the application calls them, so the
        // ledger has no length to impose on any of them.
        $source = str_repeat('s', 1000);
        $taskId = str_repeat('t', 1000);
        $revision = str_repeat('r', 1000);
        $identity = new ExecutionIdentity($source, $taskId, $this->at());

        $this->ledger()->admit($identity, $revision, $this->at(), $this->at('+1 hour'));
        $found = $this->open()->find($identity);

        $this->assertSame($source, $found?->identity->source);
        $this->assertSame($taskId, $found->identity->taskId);
        $this->assertSame($revision, $found->sourceRevision);
        $this->assertCount(1, $this->open()->unresolved($source, 10));
    }

    public function testTwoSourcesDifferingOnlyInCaseKeepTheirOwnBacklogs(): void
    {
        // Which executions belong to a schedule is the bytes the application gave, and not
        // whatever the collation of the database makes of them.
        $ledger = $this->ledger();
        $lower = new ExecutionIdentity('reports', 'daily', $this->at());
        $upper = new ExecutionIdentity('REPORTS', 'daily', $this->at());

        $ledger->admit($lower, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->admit($upper, 'rev-1', $this->at(), $this->at('+1 hour'));

        $this->assertCount(1, $ledger->unresolved('reports', 10));
        $this->assertCount(1, $ledger->unresolved('REPORTS', 10));
        $this->assertSame('reports', $ledger->unresolved('reports', 10)[0]->identity->source);
    }

    public function testTwoLedgersShareOneDatabase(): void
    {
        // An index name belongs to the schema rather than to the table it is on, so a second
        // table made the same way must not collide with the first one's index.
        $this->ledger();

        $other = new DbLedger($this->connection(), '{{%other_execution}}');
        $other->createTable();

        $identity = $this->identity();
        $other->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));

        $this->assertNull($this->open()->find($identity));
        $this->assertNotNull($other->find($identity));
    }

    public function testTheTableIsCreatedOnceWhateverAskedForIt(): void
    {
        // Two workers starting together both ask, and the one that loses is told the table is
        // there, which is what it asked for.
        $this->ledger();

        $this->open()->createTable();
        $this->expectNotToPerformAssertions();
    }

    public function testAFinishedExecutionKeepsItsPlaceUntilTheProcessIsGivenUp(): void
    {
        // Whether a record is still open is a column now, so every transition that changes the
        // answer has to write it: an outcome does not close a record that still holds the
        // process that ran it, and giving that process up closes one whose outcome is in.
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->claim($identity, $record->authority, $this->at());
        $ledger->recordLaunch($identity, $record->authority, LaunchFact::Started, $this->process(), $this->at());
        $ledger->recordOutcome($identity, $record->authority, ExecutionOutcome::Done, $this->at('+1 minute'));

        $this->assertCount(1, $ledger->unresolved($identity->source, 10));

        $ledger->releaseProcess($identity, $record->authority, new ProcessObservation(0), $this->at('+2 minutes'));

        $this->assertSame([], $ledger->unresolved($identity->source, 10));
        $this->assertSame([], $ledger->holdingProcess($identity->source, 10));
    }

    public function testAnExecutionThatNeverHeldAProcessLeavesTheBacklogWithItsOutcome(): void
    {
        // The other half of the same column: an outcome on a record holding nothing is the end
        // of it, and a record left open here would be recovered forever.
        $ledger = $this->ledger();
        $identity = $this->identity();

        $record = $ledger->admit($identity, 'rev-1', $this->at(), $this->at('+1 hour'));
        $ledger->claim($identity, $record->authority, $this->at());
        $ledger->recordLaunch($identity, $record->authority, LaunchFact::ProvenNotStarted, null, $this->at());
        $ledger->recordOutcome($identity, $record->authority, ExecutionOutcome::Failed, $this->at('+1 minute'));

        $this->assertSame([], $ledger->unresolved($identity->source, 10));
    }

    public function testTheBacklogIndexLeadsWithWhatTheBacklogQueriesAskFor(): void
    {
        // The reason the column is stored rather than worked out in the `WHERE`: this is the
        // index both queries are read from, and a database can seek by a column it has indexed
        // where it cannot by a phase being anything other than one value.
        $this->ledger();

        $sql = $this->connection()
            ->createCommand(
                "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'idx_scheduler_execution_backlog'",
            )
            ->queryScalar();

        $this->assertIsString($sql);
        $this->assertStringContainsString(
            '(source_key, unresolved, occurrence, identity_key)',
            str_replace('"', '', $sql),
        );
    }

    protected function ledger(): LedgerCommandInterface&LedgerQueryInterface
    {
        $ledger = $this->open();
        $ledger->createTable();

        return $ledger;
    }

    private function open(): DbLedger
    {
        return new DbLedger($this->connection());
    }

    private function connection(): Connection
    {
        $this->file ??= tempnam(sys_get_temp_dir(), 'scheduler-ledger-') ?: null;

        return new Connection(new Driver('sqlite:' . $this->file));
    }
}
