<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Ledger;

use Yiisoft\Db\Sqlite\Connection;
use Yiisoft\Db\Sqlite\Driver;
use Yiisoft\Scheduler\Ledger\DbLedger;
use Yiisoft\Scheduler\Ledger\ExecutionAuthority;
use Yiisoft\Scheduler\Ledger\LaunchFact;
use Yiisoft\Scheduler\Ledger\LedgerCommandInterface;
use Yiisoft\Scheduler\Ledger\LedgerQueryInterface;
use Yiisoft\Scheduler\Ledger\TransitionStatus;

use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The default adapter against the same contract, on the database it is the default for.
 *
 * A file rather than `:memory:`, because reopening the store is half of what it is for and an
 * in-memory database is gone the moment its connection is.
 */
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

        $mine = $ledger->reauthorize($identity, $record->authority, new ExecutionAuthority('mine'), $this->at());
        $theirs = $this->open()->reauthorize($identity, $record->authority, new ExecutionAuthority('theirs'), $this->at());

        $this->assertTrue($mine->isAccepted());
        $this->assertSame(TransitionStatus::StaleAuthority, $theirs->status);
        $this->assertSame('mine', $this->open()->find($identity)?->authority->token);
    }

    protected function ledger(): LedgerCommandInterface&LedgerQueryInterface
    {
        $ledger = $this->open();
        $ledger->createTable();

        return $ledger;
    }

    private function open(): DbLedger
    {
        $this->file ??= tempnam(sys_get_temp_dir(), 'scheduler-ledger-') ?: null;

        return new DbLedger(new Connection(new Driver('sqlite:' . $this->file)));
    }
}
