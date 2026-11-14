<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

use DateTimeImmutable;
use Yiisoft\Scheduler\Exception\LogicException;

use function array_filter;
use function array_slice;
use function array_values;
use function sprintf;
use function usort;

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
 * It also grows without bound, because nothing here removes a record once its work is finished,
 * and it sorts every matching record on every page rather than reading an ordered index. Both
 * are what a reference implementation can afford and a long-running planner cannot, which is
 * what a durable store backed by an indexed table is for.
 */
final class InMemoryLedger implements LedgerCommandInterface, LedgerQueryInterface
{
    /** @var array<string, ExecutionRecord> */
    private array $records = [];

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

        return $this->records[$identity->key()] = new ExecutionRecord(
            identity: $identity,
            sourceRevision: $sourceRevision,
            authority: new ExecutionAuthority($identity->key() . '/1'),
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
            static fn(ExecutionRecord $record): bool
                => LaunchFact::None === $record->launch && !$record->holdsProcess(),
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
        // counting against capacity, and that has to work after its outcome is in.
        return $this->transition(
            $identity,
            $authority,
            false,
            null,
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
        ExecutionAuthority $next,
        DateTimeImmutable $at,
    ): TransitionResult {
        if ($next->equals($expected)) {
            throw new LogicException(sprintf(
                'A new attempt needs an authority of its own, and "%s" is the one being replaced.',
                $next->token,
            ));
        }

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
                authority: $next,
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

        // Before the terminal check, because an actor holding a stale authority has no standing
        // to be told anything about the record beyond that it is not its to change.
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

        $found = [];

        foreach ($this->records as $record) {
            if ($record->identity->source !== $source || !$matches($record)) {
                continue;
            }

            $found[] = $record;
        }

        // By the occurrence they are for, so a backlog is worked through in the order it
        // happened; by key after that, so that two tasks sharing a due time have an order at
        // all and a page continued from its last record cannot miss one of them.
        usort(
            $found,
            static fn(ExecutionRecord $a, ExecutionRecord $b): int => [$a->identity->occurrence, $a->identity->key()]
                <=> [$b->identity->occurrence, $b->identity->key()],
        );

        if (null !== $after) {
            $position = [$after->occurrence, $after->key()];

            $found = array_values(array_filter(
                $found,
                static fn(ExecutionRecord $record): bool
                    => [$record->identity->occurrence, $record->identity->key()] > $position,
            ));
        }

        return array_slice($found, 0, $limit);
    }

    private static function with(
        ExecutionRecord $record,
        ?ExecutionAuthority $authority = null,
        ?ExecutionPhase $phase = null,
        ?LaunchFact $launch = null,
        ?LaunchDescription $process = null,
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
