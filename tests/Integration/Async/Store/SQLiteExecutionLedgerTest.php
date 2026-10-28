<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Store;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Db\Sqlite\{Connection, Driver};
use Yiisoft\Scheduler\Integration\Async\{ExecutionAdmission, ExecutionFence, ExecutionIdentity, ExecutionLedgerCommandInterface, ExecutionLedgerQueryInterface, ExecutionLedgerRecord, ExecutionLedgerState, ExecutionOccurrenceKey, ExecutionOutcome, ExecutionRecoveryFence};
use Yiisoft\Scheduler\Integration\Async\Store\SQLiteExecutionLedger;

use function extension_loaded;

final class SQLiteExecutionLedgerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite') || !class_exists(Connection::class) || !class_exists(Driver::class)) {
            self::markTestSkipped('SQLite ledger dependencies are not installed.');
        }
        $path = tempnam(sys_get_temp_dir(), 'scheduler-sqlite-');
        self::assertNotFalse($path);
        unlink($path);
        $this->path = $path;
    }

    protected function tearDown(): void
    {
        if (isset($this->path) && is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function testPersistsRecordsAcrossConnectionsAndKeepsPortsSeparate(): void
    {
        $ledger = $this->ledger();
        self::assertInstanceOf(ExecutionLedgerCommandInterface::class, $ledger);
        self::assertInstanceOf(ExecutionLedgerQueryInterface::class, $ledger);

        $record = $this->record('a');
        $admission = $ledger->admit($record);
        self::assertInstanceOf(ExecutionAdmission::class, $admission);
        self::assertTrue($admission->newlyAdmitted);

        $reopened = $this->ledger();
        self::assertEquals($record, $reopened->observe($record->occurrence));
        self::assertTrue($reopened->hasTaskHistory('task'));
        self::assertFalse($reopened->hasTaskHistory('missing'));
    }

    public function testKeysetCursorContinuesWhenItsPredecessorStopsBeingActive(): void
    {
        $ledger = $this->ledger();
        $first = $this->record('a');
        $second = $this->record('b');
        $ledger->admit($first);
        $ledger->admit($second);

        $page = $ledger->recoveryPage('source-1', null, 1);
        self::assertEquals([$first], $page->records);
        self::assertNotNull($page->nextCursor);

        self::assertTrue($ledger->claim($first->occurrence, $first->fence, 11, 30)->accepted);
        self::assertSame(
            ExecutionLedgerState::SUCCEEDED,
            $ledger->recordOutcome($first->occurrence, $first->fence, new ExecutionOutcome(ExecutionOutcome::SUCCEEDED), 12)->record?->state,
        );

        $continued = $ledger->recoveryPage('source-1', $page->nextCursor, 1);
        self::assertEquals([$second], $continued->records);
        self::assertNull($continued->nextCursor);
    }

    public function testRejectsIncompatibleExistingSchema(): void
    {
        $connection = $this->connection();
        $connection->createCommand('CREATE TABLE execution_ledger_schema (schema_marker TEXT NOT NULL UNIQUE)')->execute();
        $connection->createCommand("INSERT INTO execution_ledger_schema (schema_marker) VALUES ('legacy')")->execute();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('incompatible');
        $this->ledger();
    }

    private function ledger(): SQLiteExecutionLedger
    {
        return new SQLiteExecutionLedger($this->connection());
    }

    private function connection(): Connection
    {
        return new Connection(new Driver('sqlite:' . $this->path));
    }

    private function record(string $occurrenceId): ExecutionLedgerRecord
    {
        $occurrence = new ExecutionOccurrenceKey('task', $occurrenceId);
        return new ExecutionLedgerRecord(
            $occurrence,
            new ExecutionIdentity('task', $occurrenceId, 'attempt-' . $occurrenceId, 'run-' . $occurrenceId, 'execution-' . $occurrenceId),
            1,
            new ExecutionFence(1, 'fence-' . $occurrenceId),
            ExecutionLedgerState::LAUNCH_REQUIRED,
            'manifest-v1',
            [
                'schema' => 'dev',
                'source' => ['source-1', 'revision-1'],
                'task' => [
                    'id' => 'task',
                    'mode' => 'sync',
                    'timeout' => null,
                    'recoveryMaxAge' => null,
                    'metadata' => [],
                    'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]],
                    'overlap' => 'allow',
                    'missed' => ['skip', null],
                ],
            ],
            10,
            10,
            recoveryFence: new ExecutionRecoveryFence('recovery-' . $occurrenceId, 1),
            sourceIdentity: 'source-1',
            sourceRevision: 'revision-1',
        );
    }
}
