<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

use DateTimeImmutable;
use Yiisoft\Scheduler\Exception\LogicException;

use function array_splice;
use function count;
use function intdiv;
use function sprintf;
use function strcmp;

/**
 * The ledger kept in the memory of one process.
 *
 * Useful on its own for a schedule that runs in a single process and wants the lifecycle
 * without a database, and useful as the thing every other implementation is measured against:
 * `LedgerContractTest` runs the same cases over this one, so what the contract means is written
 * once in tests rather than once per adapter.
 *
 * It survives nothing. A process that exits takes its records with it, which makes it the wrong
 * choice for the recovery this ledger exists for.
 *
 * It also grows without bound, because nothing here removes a record once its work is finished.
 * That is what a reference implementation can afford and a long-running planner cannot, which
 * is what a durable store is for.
 */
final class InMemoryLedger implements LedgerCommandInterface, LedgerQueryInterface
{
    /** @var array<string, ExecutionRecord> */
    private array $records = [];
    /**
     * The keys of the records above in the order the queries read them, kept sorted as records
     * are admitted rather than sorted again on every page: a recovery pass walking a backlog
     * in small pages would otherwise pay for the whole backlog on each of them.
     *
     * An occurrence never changes after it is admitted, so a position once found stays right.
     *
     * @var list<string>
     */
    private array $order = [];

    public function admit(
        ExecutionIdentity $identity,
        string $sourceRevision,
        DateTimeImmutable $at,
        DateTimeImmutable $recoveryDeadline,
    ): ExecutionRecord {
        $existing = $this->records[$identity->key()] ?? null;

        // Idempotent: the second admission of one occurrence is the first one's record, with
        // everything that has happened to it since.
        if (null !== $existing) {
            return $existing;
        }

        array_splice($this->order, $this->position($identity), 0, [$identity->key()]);

        return $this->records[$identity->key()] = new ExecutionRecord(
            identity: $identity,
            sourceRevision: $sourceRevision,
            authority: ExecutionAuthority::forAttempt($identity, 1),
            attempt: 1,
            phase: ExecutionPhase::Admitted,
            launch: LaunchFact::None,
            process: null,
            outcome: null,
            admittedAt: $at,
            recoveryDeadline: $recoveryDeadline,
        );
    }

    public function claim(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        DateTimeImmutable $at,
    ): TransitionResult {
        return $this->transition(
            $identity,
            $authority,
            true,
            // Claimed once. Both planners that admitted this occurrence hold the authority
            // admission handed out, so the phase is what tells them apart.
            static fn(ExecutionRecord $record): bool => ExecutionPhase::Admitted === $record->phase,
            static fn(ExecutionRecord $record): ExecutionRecord => self::with(
                $record,
                phase: ExecutionPhase::Claimed,
                claimedAt: $at,
            ),
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
            // Claimed first. A claim is the right to launch, and an execution launched
            // without taking it is the race the claim exists to settle.
            static fn(ExecutionRecord $record): bool => ExecutionPhase::Claimed === $record->phase
                && LaunchFact::None === $record->launch
                && !$record->holdsProcess(),
            static fn(ExecutionRecord $record): ExecutionRecord => self::with(
                $record,
                phase: ExecutionPhase::Launched,
                launch: $launch,
                process: $process,
            ),
        );
    }

    public function recordOutcome(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        ExecutionOutcome $outcome,
        DateTimeImmutable $at,
    ): TransitionResult {
        return $this->transition(
            $identity,
            $authority,
            true,
            null,
            static fn(ExecutionRecord $record): ExecutionRecord => self::with(
                $record,
                phase: ExecutionPhase::Terminal,
                outcome: $outcome,
                resolvedAt: $at,
            ),
        );
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
        return $this->transition(
            $identity,
            $authority,
            false,
            static fn(ExecutionRecord $record): bool => $record->holdsProcess(),
            static fn(ExecutionRecord $record): ExecutionRecord => self::with(
                $record,
                process: null,
                clearProcess: true,
                processObservation: $observation,
            ),
        );
    }

    public function reauthorize(
        ExecutionIdentity $identity,
        ExecutionAuthority $expected,
        DateTimeImmutable $at,
    ): TransitionResult {
        return $this->transition(
            $identity,
            $expected,
            true,
            null,
            // The process is deliberately kept: replacing the authority does not stop the
            // worker the old one started, and a record that forgot it would leave nothing for
            // recovery to observe and stop counting against capacity.
            static fn(ExecutionRecord $record): ExecutionRecord => self::with(
                $record,
                authority: ExecutionAuthority::forAttempt($record->identity, $record->attempt + 1),
                attempt: $record->attempt + 1,
                phase: ExecutionPhase::Admitted,
                launch: LaunchFact::None,
                claimedAt: null,
                clearClaimedAt: true,
                // A previous attempt that started, or that may have, is the whole reason this
                // is worth recording: the work may be about to happen for the second time.
                mayHaveRunBefore: $record->mayHaveRunBefore
                    || LaunchFact::Started === $record->launch
                    || LaunchFact::Unknown === $record->launch,
            ),
        );
    }

    public function find(ExecutionIdentity $identity): ?ExecutionRecord
    {
        return $this->records[$identity->key()] ?? null;
    }

    public function unresolved(string $source, int $limit, ?ExecutionIdentity $after = null): array
    {
        return $this->page(
            $source,
            $limit,
            $after,
            static fn(ExecutionRecord $record): bool => $record->needsRecovery(),
        );
    }

    public function holdingProcess(string $source, int $limit, ?ExecutionIdentity $after = null): array
    {
        return $this->page(
            $source,
            $limit,
            $after,
            static fn(ExecutionRecord $record): bool => $record->holdsProcess(),
        );
    }

    /**
     * @param bool $refuseTerminal Whether an outcome already recorded forbids the change.
     * @param callable(ExecutionRecord): bool|null $allows What the record has to be for the
     * change to be allowed at all, beyond not being finished with.
     * @param callable(ExecutionRecord): ExecutionRecord $change
     */
    private function transition(
        ExecutionIdentity $identity,
        ExecutionAuthority $authority,
        bool $refuseTerminal,
        ?callable $allows,
        callable $change,
    ): TransitionResult {
        $record = $this->records[$identity->key()] ?? null;

        if (null === $record) {
            return TransitionResult::missing();
        }

        // Before the terminal check, because an authority that is not the record's makes every
        // other answer about it beside the point.
        if (!$record->authority->equals($authority)) {
            return TransitionResult::staleAuthority($record);
        }

        if ($refuseTerminal && $record->isTerminal()) {
            return TransitionResult::alreadyTerminal($record);
        }

        if (null !== $allows && !$allows($record)) {
            return TransitionResult::phaseNotAllowed($record);
        }

        return TransitionResult::accepted($this->records[$identity->key()] = $change($record));
    }

    /**
     * @param callable(ExecutionRecord): bool $matches
     *
     * @return list<ExecutionRecord>
     */
    private function page(string $source, int $limit, ?ExecutionIdentity $after, callable $matches): array
    {
        if ($limit <= 0) {
            throw new LogicException(sprintf('The limit must be greater than zero, "%d" given.', $limit));
        }

        // The order is already the one the queries promise, so a page starts where the one
        // before it ended rather than at the beginning of the backlog.
        $found = [];
        $at = null === $after ? 0 : $this->position($after);

        for ($last = count($this->order); $at < $last && count($found) < $limit; ++$at) {
            $record = $this->records[$this->order[$at]];

            if ($record->identity->source === $source && $matches($record)) {
                $found[] = $record;
            }
        }

        return $found;
    }

    /**
     * Where an identity belongs in the order: by the occurrence it is for, so a backlog is
     * worked through in the order it happened, and by key after that, so that two tasks sharing
     * a due time have an order at all and a page continued from its last record cannot miss one
     * of them.
     *
     * The first position past every record that sorts before it, which is where it is inserted
     * and, for a record already there, the position after it.
     */
    private function position(ExecutionIdentity $identity): int
    {
        $low = 0;
        $high = count($this->order);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if (self::compare($this->records[$this->order[$middle]]->identity, $identity) <= 0) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * By the instant the occurrence is for, then by the bytes of the key.
     *
     * The key is compared with `strcmp()` rather than with `<=>`, because a key is a hex digest
     * and about one in seven hundred of them is a numeric string: `1088125e73947010` is one.
     * PHP compares two numeric strings as numbers and a numeric one against an ordinary one as
     * bytes, which is not an ordering at all — `3311582943040238` sorts before
     * `1088125e73947010`, which sorts before `18a60ec26900a883`, which sorts before the first.
     * A sort over a cycle like that drops records, and a binary search over it never finds its
     * answer. It also happens to be how a database orders the same column.
     */
    private static function compare(ExecutionIdentity $a, ExecutionIdentity $b): int
    {
        return $a->occurrence <=> $b->occurrence ?: strcmp($a->key(), $b->key());
    }

    /** @param positive-int|null $attempt */
    private static function with(
        ExecutionRecord $record,
        ?ExecutionAuthority $authority = null,
        ?ExecutionPhase $phase = null,
        ?LaunchFact $launch = null,
        ?LaunchDescription $process = null,
        ?int $attempt = null,
        bool $clearProcess = false,
        ?ProcessObservation $processObservation = null,
        ?ExecutionOutcome $outcome = null,
        ?DateTimeImmutable $claimedAt = null,
        bool $clearClaimedAt = false,
        ?DateTimeImmutable $resolvedAt = null,
        ?bool $mayHaveRunBefore = null,
    ): ExecutionRecord {
        return new ExecutionRecord(
            identity: $record->identity,
            sourceRevision: $record->sourceRevision,
            authority: $authority ?? $record->authority,
            attempt: $attempt ?? $record->attempt,
            phase: $phase ?? $record->phase,
            launch: $launch ?? $record->launch,
            process: $clearProcess ? null : ($process ?? $record->process),
            outcome: $outcome ?? $record->outcome,
            admittedAt: $record->admittedAt,
            recoveryDeadline: $record->recoveryDeadline,
            processObservation: $processObservation ?? $record->processObservation,
            claimedAt: $clearClaimedAt ? null : ($claimedAt ?? $record->claimedAt),
            resolvedAt: $resolvedAt ?? $record->resolvedAt,
            mayHaveRunBefore: $mayHaveRunBefore ?? $record->mayHaveRunBefore,
        );
    }
}
