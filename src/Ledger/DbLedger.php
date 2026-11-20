<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Schema\Column\ColumnBuilder;
use Throwable;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\Exception\RuntimeException;

use function hash;
use function in_array;
use function is_bool;
use function is_callable;
use function is_int;
use function max;
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

        try {
            $this->db->createCommand()->createTable($this->table, [
                'identity_key' => ColumnBuilder::string(16)->notNull()->primaryKey(),
                // The name the application gave, beside the key every query of it is made by.
                // A source, a task id and a revision are whatever the application calls them,
                // so none of them has a length to declare here, and comparing the name itself
                // would leave which executions belong to which schedule to the collation of
                // the database: under a case-insensitive one, which is the default on MySQL,
                // two schedules named apart would read each other's backlog.
                'source' => ColumnBuilder::text()->notNull(),
                'source_key' => ColumnBuilder::string(16)->notNull(),
                'task_id' => ColumnBuilder::text()->notNull(),
                'occurrence' => ColumnBuilder::string(26)->notNull(),
                'source_revision' => ColumnBuilder::text()->notNull(),
                'authority' => ColumnBuilder::string(255)->notNull(),
                'attempt' => ColumnBuilder::integer()->notNull(),
                'phase' => ColumnBuilder::string(16)->notNull(),
                'launch' => ColumnBuilder::string(20)->notNull(),
                'process_host' => ColumnBuilder::string(255),
                'process_pid' => ColumnBuilder::integer(),
                'process_boot_id' => ColumnBuilder::string(255),
                'process_started_at' => ColumnBuilder::string(255),
                // What `unresolved()` asks for, kept beside the two columns it is read from. A
                // backlog is the few rows still open in a table that only grows, and a database
                // can seek to them by a column it has indexed, but not by `phase` being anything
                // other than one value or `process_host` being set.
                'unresolved' => ColumnBuilder::boolean()->notNull(),
                'outcome' => ColumnBuilder::string(16),
                // Whether a release was explained at all, separately from what it said. An
                // observation carrying neither an exit code nor a signal is an honest one, so
                // two null columns cannot be the way the record says there was none.
                'observation' => ColumnBuilder::boolean()->notNull(),
                'observation_exit_code' => ColumnBuilder::integer(),
                'observation_signal' => ColumnBuilder::integer(),
                'admitted_at' => ColumnBuilder::string(26)->notNull(),
                'recovery_deadline' => ColumnBuilder::string(26)->notNull(),
                'claimed_at' => ColumnBuilder::string(26),
                'resolved_at' => ColumnBuilder::string(26),
                'may_have_run_before' => ColumnBuilder::boolean()->notNull(),
            ])->execute();

            // What every query of this ledger reads: one source's backlog, in the order it
            // happened. Whether a row is still open comes before that order, so a recovery pass
            // walking a year of executions in pages reads the rows it wants rather than the
            // whole history of the source to find them.
            $this->db->createCommand()
                ->createIndex(
                    $this->table,
                    $this->indexName(),
                    ['source_key', 'unresolved', 'occurrence', 'identity_key'],
                )
                ->execute();
        } catch (Throwable $error) {
            // Two workers setting the ledger up at once both get past the check above, and the
            // one that lost is told the table is already there. That is the answer it came for,
            // so it is not a failure to start over. A table that is still missing is.
            if (null === $this->db->getTableSchema($this->table, true)) {
                throw $error;
            }
        }
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
                'source_key' => self::sourceKey($identity->source),
                'task_id' => $identity->taskId,
                'occurrence' => self::writeDate($identity->occurrence),
                'source_revision' => $sourceRevision,
                'authority' => ExecutionAuthority::forAttempt($identity, 1)->token,
                'attempt' => 1,
                'phase' => ExecutionPhase::Admitted->value,
                'launch' => LaunchFact::None->value,
                'process_host' => null,
                'process_pid' => null,
                'process_boot_id' => null,
                'process_started_at' => null,
                'unresolved' => true,
                'outcome' => null,
                'observation' => false,
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
            [
                'and',
                ['phase' => ExecutionPhase::Claimed->value],
                ['launch' => LaunchFact::None->value],
                ['process_host' => null],
            ],
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
            // An outcome does not end the backlog while the process is still held. Read from
            // `process_host`, which this statement does not write, so the order a driver works
            // through the assignments in cannot change the answer.
            'unresolved' => new Expression(
                $this->db->getQuoter()->quoteColumnName('process_host') . ' IS NOT NULL',
            ),
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
        // counting against capacity, and that has to work after its outcome is in. Refused
        // when there is no process, because the observation explains a release and a record
        // that released nothing has nothing to explain.
        return $this->transition($identity, $authority, false, ['not', ['process_host' => null]], [
            'process_host' => null,
            'process_pid' => null,
            'process_boot_id' => null,
            'process_started_at' => null,
            // Giving up the process ends the backlog only once the outcome is in. Read from
            // `phase`, which this statement does not write.
            'unresolved' => new Expression(
                $this->db->getQuoter()->quoteColumnName('phase') . ' <> :terminal',
                [':terminal' => ExecutionPhase::Terminal->value],
            ),
            'observation' => null !== $observation,
            'observation_exit_code' => $observation?->exitCode,
            'observation_signal' => $observation?->signal,
        ]);
    }

    public function reauthorize(
        ExecutionIdentity $identity,
        ExecutionAuthority $expected,
        DateTimeImmutable $at,
    ): TransitionResult {
        // The process columns are deliberately left alone: replacing the authority does not
        // stop the worker the old one started, and a record that forgot it would leave nothing
        // for recovery to observe and stop counting against capacity.
        //
        // The attempt is read in the transaction that writes it, and the `WHERE` still carries
        // the authority the caller named. An authority is minted from the attempt, so a record
        // reauthorized in between carries one this caller did not name and the write applies
        // to nothing.
        return $this->transition($identity, $expected, true, null, function (array $row) use ($identity): array {
            $attempt = (int) $row['attempt'] + 1;

            return [
                // First, and before `launch` is cleared below, because MySQL works an `UPDATE`
                // through its assignments in the order they are written and would read the
                // value this statement just put there. The other drivers read the row as it
                // stands whatever the order, so first is right everywhere.
                //
                // Read from the row being written rather than from one read a moment earlier,
                // so a launch recorded in between is still counted. A previous attempt that
                // started, or that may have, is why this is worth recording at all.
                'may_have_run_before' => new Expression(
                    sprintf(
                        '%s OR %s IN (:started, :unknown)',
                        $this->db->getQuoter()->quoteColumnName('may_have_run_before'),
                        $this->db->getQuoter()->quoteColumnName('launch'),
                    ),
                    [':started' => LaunchFact::Started->value, ':unknown' => LaunchFact::Unknown->value],
                ),
                'authority' => ExecutionAuthority::forAttempt($identity, $attempt)->token,
                'attempt' => $attempt,
                'phase' => ExecutionPhase::Admitted->value,
                'launch' => LaunchFact::None->value,
                'claimed_at' => null,
            ];
        });
    }

    public function find(ExecutionIdentity $identity): ?ExecutionRecord
    {
        $row = $this->row($identity);

        return null === $row ? null : self::record($row);
    }

    public function unresolved(string $source, int $limit, ?ExecutionIdentity $after = null): array
    {
        return $this->page($source, $limit, $after, ['unresolved' => true]);
    }

    public function holdingProcess(string $source, int $limit, ?ExecutionIdentity $after = null): array
    {
        // Every execution still holding a process is unresolved, so naming that first costs
        // nothing and lets the same index skip the source's finished history.
        return $this->page($source, $limit, $after, [
            'and',
            ['unresolved' => true],
            ['not', ['process_host' => null]],
        ]);
    }

    /**
     * Named after the table rather than fixed, because an index name belongs to the schema and
     * not to the table it is on: two ledgers in one database would collide on one name.
     */
    private function indexName(): string
    {
        return 'idx_' . $this->db->getQuoter()->getRawTableName($this->table) . '_backlog';
    }

    /**
     * One atomic transition: the `WHERE` decides whether it happens, and the row count says
     * whether it did.
     *
     * @param bool $refuseTerminal Whether an outcome already recorded forbids the change.
     * @param array<array-key, mixed>|null $allows What the row has to be for the change to be
     * allowed at all, beyond not being finished with. It goes in the same `WHERE`, so the
     * database decides it rather than a read taken a moment earlier.
     * @param array<string, mixed>|callable(array<string, mixed>): array<string, mixed> $columns
     * What to write, or how to work it out from the row as it stands. A callable is read in the
     * transaction that writes it, and the `WHERE` below still decides whether the write lands.
     */
    private function transition(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        bool $refuseTerminal,
        ?array $allows,
        array|callable $columns,
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
            if (is_callable($columns)) {
                $current = $this->row($identity);
                $columns = null === $current ? [] : $columns($current);
            }

            $changed = [] === $columns
                ? 0
                : $this->db->createCommand()->update($this->table, $columns, $condition)->execute();
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
            ->where(['source_key' => self::sourceKey($source)])
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
            attempt: max(1, (int) $row['attempt']),
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
            processObservation: !self::readBool($row['observation']) ? null : new ProcessObservation(
                null === $row['observation_exit_code'] ? null : (int) $row['observation_exit_code'],
                null === $row['observation_signal'] ? null : (int) $row['observation_signal'],
            ),
            claimedAt: null === $row['claimed_at'] ? null : self::readDate((string) $row['claimed_at']),
            resolvedAt: null === $row['resolved_at'] ? null : self::readDate((string) $row['resolved_at']),
            mayHaveRunBefore: self::readBool($row['may_have_run_before']),
        );
    }

    /**
     * What a source is found by. A hash rather than the name, so that which executions belong
     * to a schedule is decided by the bytes the application gave rather than by whether the
     * collation of the database happens to tell two names apart.
     */
    private static function sourceKey(string $source): string
    {
        return hash('xxh3', $source);
    }

    /**
     * What a driver returned for a boolean column: a bool on one, an integer on another, and
     * the string "f" on PostgreSQL, which a plain cast reads as true.
     */
    private static function readBool(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            is_int($value) => 0 !== $value,
            default => !in_array((string) $value, ['', '0', 'f', 'false'], true),
        };
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
