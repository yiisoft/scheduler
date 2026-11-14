<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Schema\Column\ColumnBuilder;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Exception\RuntimeException;

use function sprintf;

use const DATE_ATOM;
use const SORT_ASC;

/**
 * The ledger kept in a database reached through `yiisoft/db`.
 *
 * The default persistent store, and the one an application gets by installing `yiisoft/db-sqlite`
 * and pointing this at a file: SQLite gives transactions without a server to operate, which is
 * all the ledger asks of a database. Nothing here is SQLite-specific, so the same class runs on
 * any driver that package has, and an application that would rather use its own ORM implements
 * {@see LedgerCommandInterface} and {@see LedgerQueryInterface} instead and never loads this.
 *
 * Every transition is one `UPDATE` whose `WHERE` carries the authority the caller named, and the
 * number of rows it changed is the answer: a row was changed or the record was not this caller's
 * to change, with nothing in between for a crash to land in. The record is re-read afterwards in
 * the same transaction, so what comes back is what the database holds and not what the caller
 * hoped it wrote.
 *
 * The schedule checkpoint is a separate thing and stays where it is. This table holds executions.
 */
final class DbLedger implements LedgerCommandInterface, LedgerQueryInterface
{
    /**
     * Fixed width and UTC, so that the database sorts occurrences the way the ledger orders them
     * and a record written under one timezone is read back the same instant under another.
     */
    private const DATE_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $table = '{{%scheduler_execution}}',
    ) {}

    /**
     * Creates the table this ledger reads, unless it is already there.
     *
     * Here because an application should be able to try restart-safe scheduling without first
     * adopting a migration tool. One that has migrations writes this table in them instead and
     * never calls this.
     */
    public function createTable(): void
    {
        if (null !== $this->db->getTableSchema($this->table, true)) {
            return;
        }

        $this->db->createCommand()->createTable($this->table, [
            'identity_key' => ColumnBuilder::string(16)->notNull()->primaryKey(),
            'source' => ColumnBuilder::string(255)->notNull(),
            'task_id' => ColumnBuilder::string(255)->notNull(),
            'occurrence' => ColumnBuilder::string(26)->notNull(),
            'source_revision' => ColumnBuilder::string(255)->notNull(),
            'authority' => ColumnBuilder::string(255)->notNull(),
            'phase' => ColumnBuilder::string(16)->notNull(),
            'launch' => ColumnBuilder::string(20)->notNull(),
            'process_host' => ColumnBuilder::string(255),
            'process_pid' => ColumnBuilder::integer(),
            'process_boot_id' => ColumnBuilder::string(255),
            'process_started_at' => ColumnBuilder::string(255),
            'outcome' => ColumnBuilder::string(16),
            'observation_exit_code' => ColumnBuilder::integer(),
            'observation_signal' => ColumnBuilder::integer(),
            'admitted_at' => ColumnBuilder::string(26)->notNull(),
            'recovery_deadline' => ColumnBuilder::string(26)->notNull(),
            'claimed_at' => ColumnBuilder::string(26),
            'resolved_at' => ColumnBuilder::string(26),
            'may_have_run_before' => ColumnBuilder::boolean()->notNull(),
        ])->execute();

        // The order every query of this ledger reads in, so that a recovery pass walking a year
        // of executions in pages reads an index rather than the table.
        $this->db->createCommand()
            ->createIndex($this->table, 'idx_scheduler_execution_backlog', ['source', 'occurrence', 'identity_key'])
            ->execute();
    }

    public function admit(
        ExecutionIdentity $identity,
        string $sourceRevision,
        DateTimeImmutable $at,
        DateTimeImmutable $recoveryDeadline,
    ): ExecutionRecord {
        /** @var ExecutionRecord */
        return $this->db->transaction(function () use ($identity, $sourceRevision, $at, $recoveryDeadline) {
            // Insert, and on a row that is already there change nothing: the second admission of
            // one occurrence is the first one's record, with everything that has happened to it
            // since. This is the handoff a crashed planner comes back to.
            $this->db->createCommand()->upsert($this->table, [
                'identity_key' => $identity->key(),
                'source' => $identity->source,
                'task_id' => $identity->taskId,
                'occurrence' => self::writeDate($identity->occurrence),
                'source_revision' => $sourceRevision,
                'authority' => $identity->key() . '/1',
                'phase' => ExecutionPhase::Admitted->value,
                'launch' => LaunchFact::None->value,
                'process_host' => null,
                'process_pid' => null,
                'process_boot_id' => null,
                'process_started_at' => null,
                'outcome' => null,
                'observation_exit_code' => null,
                'observation_signal' => null,
                'admitted_at' => self::writeDate($at),
                'recovery_deadline' => self::writeDate($recoveryDeadline),
                'claimed_at' => null,
                'resolved_at' => null,
                'may_have_run_before' => false,
            ], false)->execute();

            $row = $this->row($identity);

            if (null === $row) {
                throw new RuntimeException(sprintf(
                    'The execution of task "%s" at %s was admitted and is not in the ledger.',
                    $identity->taskId,
                    $identity->occurrence->format(DATE_ATOM),
                ));
            }

            return self::record($row);
        });
    }

    public function claim(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        DateTimeImmutable $at,
    ): TransitionResult {
        // Claimed once. Both planners that admitted this occurrence hold the authority
        // admission handed out, so the phase is what tells them apart.
        return $this->transition(
            $identity,
            $authority,
            true,
            ['phase' => ExecutionPhase::Admitted->value],
            [
                'phase' => ExecutionPhase::Claimed->value,
                'claimed_at' => self::writeDate($at),
            ],
        );
    }

    public function recordLaunch(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        LaunchFact $launch,
        ?LaunchDescription $process,
        DateTimeImmutable $at,
    ): TransitionResult {
        if ((LaunchFact::Started === $launch) !== (null !== $process)) {
            throw new LogicException(
                'A started launch is described by its process and any other launch by nothing:'
                . ' there is no process to describe before one starts, and none known to exist'
                . ' when the result of starting it was never learned.',
            );
        }

        return $this->transition(
            $identity,
            $authority,
            true,
            ['and', ['launch' => LaunchFact::None->value], ['process_host' => null]],
            [
                'phase' => ExecutionPhase::Launched->value,
                'launch' => $launch->value,
                'process_host' => $process?->host,
                'process_pid' => $process?->pid,
                'process_boot_id' => $process?->bootId,
                'process_started_at' => $process?->startedAt,
            ],
        );
    }

    public function recordOutcome(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        ExecutionOutcome $outcome,
        DateTimeImmutable $at,
    ): TransitionResult {
        return $this->transition($identity, $authority, true, null, [
            'phase' => ExecutionPhase::Terminal->value,
            'outcome' => $outcome->value,
            'resolved_at' => self::writeDate($at),
        ]);
    }

    public function releaseProcess(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        ?ProcessObservation $observation,
        DateTimeImmutable $at,
    ): TransitionResult {
        // Not refused once terminal: giving up a process is how a finished execution stops
        // counting against capacity, and that has to work after its outcome is in.
        return $this->transition($identity, $authority, false, null, [
            'process_host' => null,
            'process_pid' => null,
            'process_boot_id' => null,
            'process_started_at' => null,
            'observation_exit_code' => $observation?->exitCode,
            'observation_signal' => $observation?->signal,
        ]);
    }

    public function reauthorize(
        ExecutionIdentity $identity,
        ExecutionAuthority $expected,
        ExecutionAuthority $next,
        DateTimeImmutable $at,
    ): TransitionResult {
        if ($next->equals($expected)) {
            throw new LogicException(sprintf(
                'A new attempt needs an authority of its own, and "%s" is the one being replaced.',
                $next->token,
            ));
        }

        // The process columns are deliberately left alone: replacing the authority does not
        // stop the worker the old one started, and a record that forgot it would leave nothing
        // for recovery to observe and stop counting against capacity.
        return $this->transition($identity, $expected, true, null, [
            'authority' => $next->token,
            'phase' => ExecutionPhase::Admitted->value,
            'launch' => LaunchFact::None->value,
            'claimed_at' => null,
            // Read from the row being written rather than from one read a moment earlier, so a
            // launch recorded in between is still counted. A previous attempt that started, or
            // that may have, is the whole reason this is worth recording.
            'may_have_run_before' => new Expression(
                sprintf(
                    '%s OR %s IN (:started, :unknown)',
                    $this->db->getQuoter()->quoteColumnName('may_have_run_before'),
                    $this->db->getQuoter()->quoteColumnName('launch'),
                ),
                [':started' => LaunchFact::Started->value, ':unknown' => LaunchFact::Unknown->value],
            ),
        ]);
    }

    public function find(ExecutionIdentity $identity): ?ExecutionRecord
    {
        $row = $this->row($identity);

        return null === $row ? null : self::record($row);
    }

    public function unresolved(string $source, int $limit, ?ExecutionIdentity $after = null): array
    {
        return $this->page($source, $limit, $after, [
            'or',
            ['<>', 'phase', ExecutionPhase::Terminal->value],
            ['not', ['process_host' => null]],
        ]);
    }

    public function holdingProcess(string $source, int $limit, ?ExecutionIdentity $after = null): array
    {
        return $this->page($source, $limit, $after, ['not', ['process_host' => null]]);
    }

    /**
     * One atomic transition: the `WHERE` decides whether it happens, and the row count says
     * whether it did.
     *
     * @param bool $refuseTerminal Whether an outcome already recorded forbids the change.
     * @param array<array-key, mixed>|null $allows What the row has to be for the change to be
     * allowed at all, beyond not being finished with. It goes in the same `WHERE`, so the
     * database decides it rather than a read taken a moment earlier.
     * @param array<string, mixed> $columns
     */
    private function transition(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        bool $refuseTerminal,
        ?array $allows,
        array $columns,
    ): TransitionResult {
        $condition = [
            'and',
            ['identity_key' => $identity->key(), 'authority' => $authority->token],
        ];

        if ($refuseTerminal) {
            $condition[] = ['<>', 'phase', ExecutionPhase::Terminal->value];
        }

        if (null !== $allows) {
            $condition[] = $allows;
        }

        /** @var TransitionResult */
        return $this->db->transaction(function () use ($identity, $authority, $refuseTerminal, $columns, $condition) {
            $changed = $this->db->createCommand()->update($this->table, $columns, $condition)->execute();
            $row = $this->row($identity);

            if ($changed > 0) {
                /** @var array<string, mixed> $row */
                return TransitionResult::accepted(self::record($row));
            }

            if (null === $row) {
                return TransitionResult::missing();
            }

            $record = self::record($row);

            // Authority before terminal, as an actor holding a stale one has no standing to be
            // told anything about the record beyond that it is not its to change.
            if (!$record->authority->equals($authority)) {
                return TransitionResult::staleAuthority($record);
            }

            return $refuseTerminal && $record->isTerminal()
                ? TransitionResult::alreadyTerminal($record)
                : TransitionResult::phaseNotAllowed($record);
        });
    }

    /**
     * @param array<array-key, mixed> $matching
     *
     * @return list<ExecutionRecord>
     */
    private function page(string $source, int $limit, ?ExecutionIdentity $after, array $matching): array
    {
        if ($limit <= 0) {
            throw new LogicException(sprintf('The limit must be greater than zero, "%d" given.', $limit));
        }

        $query = $this->db
            ->createQuery()
            ->from($this->table)
            ->where(['source' => $source])
            ->andWhere($matching)
            // By the occurrence they are for, so a backlog is worked through in the order it
            // happened; by key after that, so that two tasks sharing a due time have an order at
            // all and a page continued from its last record cannot miss one of them.
            ->orderBy(['occurrence' => SORT_ASC, 'identity_key' => SORT_ASC])
            ->limit($limit);

        if (null !== $after) {
            $occurrence = self::writeDate($after->occurrence);

            $query->andWhere([
                'or',
                ['>', 'occurrence', $occurrence],
                ['and', ['occurrence' => $occurrence], ['>', 'identity_key', $after->key()]],
            ]);
        }

        $records = [];

        /** @var array<string, mixed> $row */
        foreach ($query->all() as $row) {
            $records[] = self::record($row);
        }

        return $records;
    }

    /** @return array<string, mixed>|null */
    private function row(ExecutionIdentity $identity): ?array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->db
            ->createQuery()
            ->from($this->table)
            ->where(['identity_key' => $identity->key()])
            ->one();

        return $row;
    }

    /** @param array<string, mixed> $row */
    private static function record(array $row): ExecutionRecord
    {
        return new ExecutionRecord(
            identity: new ExecutionIdentity(
                (string) $row['source'],
                (string) $row['task_id'],
                self::readDate((string) $row['occurrence']),
            ),
            sourceRevision: (string) $row['source_revision'],
            authority: new ExecutionAuthority((string) $row['authority']),
            phase: ExecutionPhase::from((string) $row['phase']),
            launch: LaunchFact::from((string) $row['launch']),
            process: null === $row['process_host'] ? null : new LaunchDescription(
                (string) $row['process_host'],
                (int) $row['process_pid'],
                null === $row['process_boot_id'] ? null : (string) $row['process_boot_id'],
                null === $row['process_started_at'] ? null : (string) $row['process_started_at'],
            ),
            outcome: null === $row['outcome'] ? null : ExecutionOutcome::from((string) $row['outcome']),
            admittedAt: self::readDate((string) $row['admitted_at']),
            recoveryDeadline: self::readDate((string) $row['recovery_deadline']),
            processObservation: null === $row['observation_exit_code'] && null === $row['observation_signal']
                ? null
                : new ProcessObservation(
                    null === $row['observation_exit_code'] ? null : (int) $row['observation_exit_code'],
                    null === $row['observation_signal'] ? null : (int) $row['observation_signal'],
                ),
            claimedAt: null === $row['claimed_at'] ? null : self::readDate((string) $row['claimed_at']),
            resolvedAt: null === $row['resolved_at'] ? null : self::readDate((string) $row['resolved_at']),
            mayHaveRunBefore: (bool) $row['may_have_run_before'],
        );
    }

    private static function writeDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
    }

    private static function readDate(string $written): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $written, new DateTimeZone('UTC'));

        if (false === $date) {
            throw new RuntimeException(sprintf('The ledger holds "%s" where a date was written.', $written));
        }

        return $date;
    }
}
