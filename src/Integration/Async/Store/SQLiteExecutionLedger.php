<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Store;

use InvalidArgumentException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Throwable;
use Yiisoft\Scheduler\Integration\Async\Recovery\RecoveryDeadline;
use Yiisoft\Scheduler\Integration\Async\{ExecutionAdmission,ExecutionAggregateRedeliveryInterface,ExecutionClaimResult,ExecutionCommandScope,ExecutionDispatchReservation,ExecutionDispatchResult,ExecutionFence,ExecutionGenerationResult,ExecutionLaunchDescriptor,ExecutionLaunchDescriptorLedgerInterface,ExecutionLaunchObservation,ExecutionLedgerCommandInterface,ExecutionLedgerQueryInterface,ExecutionLedgerRecord,ExecutionLedgerRecoveryPage,ExecutionLedgerState,ExecutionOccurrenceKey,ExecutionOutcome,ExecutionProcessClosure,ExecutionProcessIdentity,ExecutionRecoveryFence,ExecutionStartObservation,ExecutionRecoveryResult,ExecutionRevocationResult,ExecutionTerminalResult};
use Yiisoft\Scheduler\Integration\Async\ExecutionRetiredGeneration;

use function array_keys;
use function count;
use function in_array;
use function is_array;
use function is_float;
use function is_string;
use function sprintf;
use function strlen;
use function substr;
use function array_slice;

use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;
use const JSON_UNESCAPED_SLASHES;

/** SQLite-backed local ledger; coordination is atomic within one SQLite database. */
final class SQLiteExecutionLedger implements ExecutionLedgerCommandInterface, ExecutionLedgerQueryInterface, ExecutionLaunchDescriptorLedgerInterface, ExecutionAggregateRedeliveryInterface
{
    private const SCHEMA = 'dev';
    private readonly ExecutionLedgerPayloadCodec $codec;
    private ConnectionInterface $connection;

    public function __construct(ConnectionInterface $connection, ?ExecutionLedgerPayloadCodec $codec = null)
    {
        $this->connection = $connection;
        $this->codec = $codec ?? new ExecutionLedgerPayloadCodec();
        $this->connection->transaction(function (): void {
            $tables = $this->connection->createCommand(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN ('execution_ledger_schema', 'execution_ledger', 'execution_ledger_lock') ORDER BY name",
            )->queryAll();
            /** @var list<array<string,mixed>> $tables */
            $names = array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $tables);
            sort($names);
            $expected = ['execution_ledger', 'execution_ledger_lock', 'execution_ledger_schema'];
            if ($names !== [] && $names !== $expected) {
                throw new InvalidArgumentException('SQLite execution ledger schema is incomplete or incompatible; use a new database (migrations are not supported).');
            }
            if ($names === []) {
                $this->connection->createCommand('CREATE TABLE execution_ledger_schema (schema_marker TEXT NOT NULL UNIQUE)')->execute();
                $this->connection->createCommand('CREATE TABLE execution_ledger (task_key TEXT NOT NULL, occurrence_id TEXT NOT NULL, source_identity TEXT NOT NULL, state TEXT NOT NULL, active INTEGER NOT NULL, ownership INTEGER NOT NULL, payload TEXT NOT NULL, PRIMARY KEY(task_key, occurrence_id))')->execute();
                $this->connection->createCommand('CREATE TABLE execution_ledger_lock (lock_name TEXT NOT NULL PRIMARY KEY, touched_at INTEGER NOT NULL)')->execute();
                $this->connection->createCommand('INSERT INTO execution_ledger_schema (schema_marker) VALUES (:schema)', ['schema' => self::SCHEMA])->execute();
                $this->connection->createCommand("INSERT INTO execution_ledger_lock (lock_name, touched_at) VALUES ('ledger', 0)")->execute();
            } else {
                $this->validateSqliteSchema(false);
            }
            $this->ensureIndexes();
            $this->validateSqliteSchema();
        });
    }

    public function admit(ExecutionLedgerRecord $record): ExecutionAdmission
    {
        $encoded = $this->codec->encode($record);
        return $this->connection->transaction(function () use ($record, $encoded): ExecutionAdmission {
            $this->lock();
            $old = $this->read($record->occurrence);
            if ($old !== null) {
                return new ExecutionAdmission($old, false);
            }
            $this->write($record);
            return new ExecutionAdmission($record, true);
        });
    }

    public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
    {
        return $this->read($occurrence);
    }

    public function activeExecutions(string $sourceIdentity): array
    {
        return $this->sqlList($sourceIdentity, 'active=1');
    }

    public function ownershipHoldingExecutions(string $sourceIdentity): array
    {
        return $this->sqlList($sourceIdentity, 'ownership=1');
    }

    public function hasTaskHistory(string $taskKey): bool
    {
        $row = $this->connection->createCommand(
            'SELECT 1 FROM execution_ledger WHERE task_key = :task LIMIT 1',
            ['task' => $taskKey],
        )->queryOne();
        return $row !== null;
    }

    public function recoveryPage(string $sourceIdentity, ?string $cursor, int $limit): ExecutionLedgerRecoveryPage
    {
        if ($limit < 1 || $limit > ExecutionLedgerQueryInterface::MAX_RECOVERY_PAGE_SIZE) {
            throw new InvalidArgumentException(sprintf('Recovery page limit must be between 1 and %d.', ExecutionLedgerQueryInterface::MAX_RECOVERY_PAGE_SIZE));
        }
        $after = null;
        if ($cursor !== null) {
            $after = $this->decodeKeysetCursor($sourceIdentity, $cursor);
        }
        $sql = 'SELECT task_key, occurrence_id, source_identity, payload FROM execution_ledger WHERE source_identity = :source AND (active = 1 OR ownership = 1)';
        $params = ['source' => $sourceIdentity, 'limit' => $limit + 1];
        if ($after !== null) {
            $sql .= ' AND (task_key > :afterTask OR (task_key = :afterTask AND occurrence_id > :afterOccurrence))';
            $params['afterTask'] = $after[0];
            $params['afterOccurrence'] = $after[1];
        }
        $rows = $this->connection->createCommand($sql . ' ORDER BY task_key, occurrence_id LIMIT :limit', $params)->queryAll();
        /** @var list<array<string,mixed>> $rows */
        $records = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $records[] = $this->decodeRow($row);
        }
        $next = count($rows) > $limit && $records !== [] ? $records[array_key_last($records)] : null;
        return new ExecutionLedgerRecoveryPage(
            $records,
            $next === null ? null : $this->encodeKeysetCursor($sourceIdentity, $next->occurrence),
        );
    }

    public function repairIndexes(): void
    {
        $this->connection->transaction(function (): void {
            $this->validateSqliteSchema(false);
            $this->lock();
            $this->ensureIndexes();
            $this->validateSqliteSchema();
        });
    }

    public function claim(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, int $at, ?int $claimLeaseDeadline, ?ExecutionLaunchDescriptor $descriptor = null, ?string $permissionId = null): ExecutionClaimResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $fence, $at, $claimLeaseDeadline, $descriptor, $permissionId): ExecutionClaimResult {
            $r = $this->read($occurrence);
            $async = ($r?->taskSnapshot['task']['mode'] ?? null) === 'async';
            $receiptRequired = $async || $descriptor !== null || $permissionId !== null;
            if ($receiptRequired && ($descriptor === null || $permissionId === null)) {
                return ExecutionClaimResult::rejected('worker receipt is incomplete');
            }
            if ($r === null) {
                return ExecutionClaimResult::rejected('stale or unavailable execution');
            }
            if (!$r->fence->equals($fence) || $r->isTerminal()
                || !in_array($r->state, [ExecutionLedgerState::LAUNCH_REQUIRED,ExecutionLedgerState::LAUNCH_REQUESTED,ExecutionLedgerState::NEEDS_RESOLUTION], true)
                || $r->dispatchReservation?->launchPhase === ExecutionDispatchReservation::AMBIGUOUS
                || ($claimLeaseDeadline !== null && $claimLeaseDeadline < $at)) {
                return ExecutionClaimResult::rejected('stale or unavailable execution');
            }
            if ($receiptRequired && !$this->hasCompleteWorkerLaunchReceipt($r, $descriptor, $permissionId)) {
                return ExecutionClaimResult::rejected('complete worker launch receipt is required');
            }
            $n = $this->copy($r, ExecutionLedgerState::CLAIMED, $at, null, claim: $claimLeaseDeadline);
            $this->write($n);
            return ExecutionClaimResult::accepted($n);
        });
    }

    public function launchUnderLock(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, string $sourceIdentity, ExecutionLaunchDescriptor $descriptor, string $permissionId, int $at, callable $start, int $nowUs): ExecutionDispatchResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $expectedFence, $recoveryFence, $sourceIdentity, $descriptor, $permissionId, $at, $start, $nowUs): ExecutionDispatchResult {
            $r = $this->read($occurrence);
            if ($r === null || !$r->fence->equals($expectedFence) || !$this->recoveryMatches($r, $recoveryFence, $sourceIdentity)
                || $r->isTerminal() || $r->state !== ExecutionLedgerState::LAUNCH_REQUESTED
                || $r->dispatchReservation === null || $r->dispatchReservation->permissionId !== $permissionId
                || $r->dispatchReservation->launchPhase !== ExecutionDispatchReservation::PREPARED
                || $r->dispatchReservation->deadline <= $at
                || ($r->recoveryDeadline !== null && $r->recoveryDeadline <= $nowUs)
                || $r->launchDescriptor === null || !$r->launchDescriptor->equals($descriptor)) {
                return ExecutionDispatchResult::stale('launch permission is not current and prepared');
            }
            $starting = $this->copy($r, ExecutionLedgerState::LAUNCH_REQUESTED, $r->updatedAt, 'keep', dispatch: $r->dispatchReservation->withPhase(ExecutionDispatchReservation::STARTING));
            $this->write($starting);
            $startingReservation = $starting->dispatchReservation;
            try {
                $observation = $start();
                if (!$observation instanceof ExecutionLaunchObservation || $observation->processIdentity === null || $observation->startObservation === null
                    || ($observation->pid !== null && $observation->pid !== $observation->processIdentity->pid)) {
                    throw new InvalidArgumentException('Launch callback returned an incomplete or inconsistent receipt.');
                }
                $launchProcessIdentity = $observation->processIdentity;
                $launchStartObservation = $observation->startObservation;
                if ($startingReservation === null) {
                    throw new InvalidArgumentException('Starting dispatch reservation is missing.');
                }
                $observedReservation = $startingReservation->withPhase(ExecutionDispatchReservation::OBSERVED);
                // The first authoritative launch establishes the recovery anchor. A
                // later generation only publishes its current attempt facts; the
                // original anchor and recovery window remain immutable.
                $anchorIdentity = $starting->startAnchorIdentity ?? $launchProcessIdentity;
                $anchorStart = $starting->startObservation ?? $launchStartObservation;
                $recoveryAge = $starting->recoveryAge ?? $this->recoveryAge($starting);
                $recoveryDeadline = $starting->recoveryDeadline ?? RecoveryDeadline::forStart($starting, $launchStartObservation);
                $n = $this->copy($starting, ExecutionLedgerState::NEEDS_RESOLUTION, $observation->observedAt, null, dispatch: $observedReservation, launch: $observation, identity: $launchProcessIdentity, anchorIdentity: $anchorIdentity, start: $anchorStart, attemptStart: $launchStartObservation, recoveryAge: $recoveryAge, recoveryDeadline: $recoveryDeadline);
                $this->write($n);
                return ExecutionDispatchResult::accepted($n);
            } catch (Throwable $e) {
                $startingReservation = $starting->dispatchReservation;
                $ambiguous = $startingReservation === null
                    ? null
                    : $startingReservation->withPhase(ExecutionDispatchReservation::AMBIGUOUS);
                $n = $this->copy($starting, ExecutionLedgerState::LAUNCH_AMBIGUOUS, $starting->updatedAt, null, dispatch: $ambiguous);
                $this->write($n);
                $message = $e->getMessage();
                if ($message === '') {
                    $message = 'launch callback failed';
                } elseif (strlen($message) > 4096) {
                    $message = substr($message, 0, 4096);
                }
                return ExecutionDispatchResult::ambiguous($n, $message);
            }
        });
    }

    public function prepareCommandUnderLock(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, string $sourceIdentity, ExecutionLaunchDescriptor $rootDescriptor, ExecutionLaunchDescriptor $commandDescriptor, int $at, int $nowUs): ExecutionDispatchResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $expectedFence, $recoveryFence, $sourceIdentity, $rootDescriptor, $commandDescriptor, $at, $nowUs): ExecutionDispatchResult {
            $r = $this->read($occurrence);
            if ($r === null || !$r->fence->equals($expectedFence) || !$this->recoveryMatches($r, $recoveryFence, $sourceIdentity)
                || $r->launchDescriptor === null || !$r->launchDescriptor->equals($rootDescriptor)
                || !$commandDescriptor->rootDescriptor?->equals($rootDescriptor)
                || !$commandDescriptor->occurrence?->equals($occurrence)
                || $commandDescriptor->generation !== $expectedFence->generation
                || !in_array($r->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING], true)
                || $r->isTerminal() || $r->revokedAt !== null || ($r->claimLeaseDeadline !== null && $at >= $r->claimLeaseDeadline)
                || ($r->recoveryDeadline !== null && $nowUs >= $r->recoveryDeadline)
                || $this->hasConfirmedClosure($r) || $r->commandScope !== null || $r->dispatchReservation === null
                || $r->dispatchReservation->deadline <= $at) {
                return ExecutionDispatchResult::stale('command authority is not current');
            }
            $dispatchReservation = $r->dispatchReservation;
            $scope = new ExecutionCommandScope(
                $commandDescriptor,
                new ExecutionDispatchReservation($dispatchReservation->dispatcherId, $at, $dispatchReservation->deadline),
            );
            $n = $r->transition(commandScope: $scope, updatedAt: $r->updatedAt);
            $this->write($n);
            return ExecutionDispatchResult::accepted($n);
        });
    }

    public function launchCommandUnderLock(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, string $sourceIdentity, ExecutionLaunchDescriptor $rootDescriptor, ExecutionCommandScope $preparedScope, int $at, callable $start, int $nowUs): ExecutionDispatchResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $expectedFence, $recoveryFence, $sourceIdentity, $rootDescriptor, $preparedScope, $at, $start, $nowUs): ExecutionDispatchResult {
            $r = $this->read($occurrence);
            if ($r === null) {
                return ExecutionDispatchResult::stale('command launch permission is not current and prepared');
            }
            $valid = $this->commandPermissionValid($r, $preparedScope, $rootDescriptor, $at, $nowUs)
                && $r->fence->equals($expectedFence)
                && $this->recoveryMatches($r, $recoveryFence, $sourceIdentity)
                && $r->commandScope !== null && $r->commandScope->descriptor->equals($preparedScope->descriptor)
                && $r->commandScope->reservation->permissionId === $preparedScope->reservation->permissionId
                && $r->commandScope->reservation->dispatcherId === $preparedScope->reservation->dispatcherId
                && $r->commandScope->reservation->reservedAt === $preparedScope->reservation->reservedAt
                && $r->commandScope->reservation->deadline === $preparedScope->reservation->deadline;
            if (!$valid) {
                return ExecutionDispatchResult::stale('command launch permission is not current and prepared');
            }
            $commandScope = $r->commandScope;
            $preparedScope = $commandScope;
            $startingScope = $preparedScope->transition(reservation: $preparedScope->reservation->withPhase(ExecutionDispatchReservation::STARTING));
            $starting = $r->transition(commandScope: $startingScope, updatedAt: $r->updatedAt);
            $this->write($starting);
            try {
                $observation = $start();
                if (!$observation instanceof ExecutionLaunchObservation || $observation->processIdentity === null || $observation->startObservation === null) {
                    throw new InvalidArgumentException('Command launch callback returned an incomplete receipt.');
                }
                // Handles are process-local and must never cross the durable record boundary.
                $durableObservation = new ExecutionLaunchObservation($observation->pid, $observation->error, $observation->classification, $observation->observedAt, $observation->closure, null, $observation->processIdentity, $observation->startObservation);
                $observed = $startingScope->transition(reservation: $startingScope->reservation->withPhase(ExecutionDispatchReservation::OBSERVED), observation: $durableObservation);
                $n = $starting->transition(commandScope: $observed, updatedAt: $observation->observedAt);
                $this->write($n);
                return ExecutionDispatchResult::accepted($n);
            } catch (Throwable $e) {
                $message = $e->getMessage() === '' ? 'command launch callback failed' : substr($e->getMessage(), 0, 4096);
                $ambiguous = $startingScope->transition(reservation: $startingScope->reservation->withPhase(ExecutionDispatchReservation::AMBIGUOUS), observation: new ExecutionLaunchObservation(null, $message, 'ambiguous', intdiv($nowUs, 1_000_000)));
                $n = $starting->transition(commandScope: $ambiguous, updatedAt: $starting->updatedAt);
                $this->write($n);
                return ExecutionDispatchResult::ambiguous($n, $message);
            }
        });
    }

    public function observeCommandUnderLock(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, string $sourceIdentity, ExecutionLaunchDescriptor $rootDescriptor, ExecutionLaunchDescriptor $commandDescriptor, ExecutionLaunchObservation $observation): ExecutionDispatchResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $expectedFence, $recoveryFence, $sourceIdentity, $rootDescriptor, $commandDescriptor, $observation): ExecutionDispatchResult {
            $r = $this->read($occurrence);
            if ($r === null) {
                return ExecutionDispatchResult::stale('command observation is not current');
            }
            $scope = $r->commandScope;
            if ($scope === null) {
                return ExecutionDispatchResult::stale('command observation is not current');
            }
            if (!$r->fence->equals($expectedFence) || !$this->recoveryMatches($r, $recoveryFence, $sourceIdentity)
                || $r->launchDescriptor === null || !$r->launchDescriptor->equals($rootDescriptor)
                || !$scope->descriptor->equals($commandDescriptor)
                || $scope->descriptor->rootDescriptor?->equals($rootDescriptor) !== true
                || $scope->descriptor->occurrence?->equals($occurrence) !== true
                || $scope->descriptor->generation !== $expectedFence->generation
                || $scope->reservation->launchPhase !== ExecutionDispatchReservation::OBSERVED
                || $observation->processIdentity === null || $observation->startObservation === null
                || $observation->pid !== $observation->processIdentity->pid) {
                return ExecutionDispatchResult::stale('command observation is not current');
            }
            $existing = $scope->observation;
            if ($existing?->closure?->isConfirmed() === true && $observation->closure?->isConfirmed() !== true) {
                return ExecutionDispatchResult::accepted($r);
            }
            if ($existing !== null) {
                if ($existing->closure?->isConfirmed() === true && $observation->closure?->isConfirmed() === true
                    && !$this->sameClosure($existing->closure, $observation->closure)) {
                    return ExecutionDispatchResult::rejected('conflicting command closure facts');
                }
                if ($existing->processIdentity !== null && !$this->sameProcessIdentity($existing->processIdentity, $observation->processIdentity)) {
                    return ExecutionDispatchResult::rejected('conflicting command identity facts');
                }
                if ($existing->startObservation !== null && !$this->sameStartObservation($existing->startObservation, $observation->startObservation)) {
                    return ExecutionDispatchResult::rejected('conflicting command start facts');
                }
            }
            $merged = $this->mergeObservation($existing, $observation);
            $n = $r->transition(commandScope: $scope->transition(observation: $merged), updatedAt: max($r->updatedAt, $observation->observedAt));
            if ($existing !== null && $this->sameObservation($existing, $merged)) {
                return ExecutionDispatchResult::accepted($r);
            }
            $this->write($n);
            return ExecutionDispatchResult::accepted($n);
        });
    }

    public function repairBeforeStartUnderLock(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, string $sourceIdentity, callable $absenceProof, int $nowUs): ExecutionDispatchResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $expectedFence, $recoveryFence, $sourceIdentity, $absenceProof, $nowUs): ExecutionDispatchResult {
            $r = $this->read($occurrence);
            $invalidRepair = $r === null || !$r->fence->equals($expectedFence) || !$this->recoveryMatches($r, $recoveryFence, $sourceIdentity)
                || $r->dispatchReservation === null || !in_array($r->dispatchReservation->launchPhase, [ExecutionDispatchReservation::STARTING, ExecutionDispatchReservation::AMBIGUOUS], true)
                || $r->startAnchorIdentity !== null || $r->startObservation !== null
                || $r->launchObservation?->startObservation !== null
                || $r->state === ExecutionLedgerState::CLAIMED || $r->isTerminal()
                || ($r->recoveryDeadline !== null && $r->recoveryDeadline <= $nowUs) || !$absenceProof();
            if ($invalidRepair) {
                return ExecutionDispatchResult::rejected('pre-start absence proof is incomplete');
            }
            if ($r === null || $r->dispatchReservation === null) {
                return ExecutionDispatchResult::rejected('pre-start absence proof is incomplete');
            }
            // Refresh dispatch timing from the explicit recovery-clock reading so
            // the repaired permission can actually be launched now. Recovery
            // timing remains fenced by the original deadline and is not extended.
            $old = $r->dispatchReservation;
            $dispatchAt = intdiv($nowUs, 1_000_000);
            if ($old->deadline < $old->reservedAt) {
                return ExecutionDispatchResult::rejected('dispatch duration is invalid or overflows');
            }
            // PHP_INT_MAX is the contract's unlimited reservation sentinel. Do
            // not turn it into a subtract/add duration: that calculation can
            // overflow even though the reservation itself is valid.
            if ($old->deadline === PHP_INT_MAX) {
                $deadline = PHP_INT_MAX;
            } else {
                $duration = $old->deadline - $old->reservedAt;
                if ($dispatchAt > PHP_INT_MAX - $duration) {
                    return ExecutionDispatchResult::rejected('dispatch duration is invalid or overflows');
                }
                $deadline = $dispatchAt + $duration;
            }
            $reservation = new ExecutionDispatchReservation($old->dispatcherId, $dispatchAt, $deadline);
            $n = $this->copy(
                $r,
                ExecutionLedgerState::LAUNCH_REQUESTED,
                $dispatchAt,
                null,
                dispatch: $reservation,
                launch: null,
                identity: null,
                attemptStart: null,
            );
            $this->write($n);
            return ExecutionDispatchResult::sameGenerationUnclaimed($n);
        });
    }

    public function rotateAfterClosureUnderLock(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $recoveryFence, ExecutionFence $newFence, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionGenerationResult
    {
        return $this->withLock(
            $occurrence,
            fn(): ExecutionGenerationResult => $this->allocateGenerationUnderLock($occurrence, $recoveryFence, $newFence, $nowUs, $expectedFence, $sourceIdentity),
        );
    }

    public function redeliverAfterClosureUnderLock(
        ExecutionOccurrenceKey $occurrence,
        ExecutionRecoveryFence $recoveryFence,
        ExecutionFence $newFence,
        int $nowUs,
        ExecutionFence $expectedFence,
        string $sourceIdentity,
        ExecutionLaunchObservation $rootClosure,
        ?ExecutionLaunchObservation $commandClosure,
    ): ExecutionGenerationResult {
        return $this->withLock($occurrence, function () use (
            $occurrence,
            $recoveryFence,
            $newFence,
            $nowUs,
            $expectedFence,
            $sourceIdentity,
            $rootClosure,
            $commandClosure,
        ): ExecutionGenerationResult {
            $record = $this->read($occurrence);
            if ($record === null || !$record->fence->equals($expectedFence)
                || !$this->recoveryMatches($record, $recoveryFence, $sourceIdentity)) {
                return ExecutionGenerationResult::stale();
            }
            if ($record->isTerminal()) {
                return ExecutionGenerationResult::rejected('terminal outcome is authoritative');
            }
            if ($record->recoveryDeadline !== null && $nowUs >= $record->recoveryDeadline) {
                return ExecutionGenerationResult::rejected('recovery deadline has elapsed');
            }
            if ($rootClosure->closure?->isConfirmed() !== true || $rootClosure->processIdentity === null) {
                return ExecutionGenerationResult::rejected('root closure proof is incomplete');
            }
            $record = $this->mergeClosedRoot($record, $rootClosure);
            if ($record === null) {
                return ExecutionGenerationResult::rejected('root closure facts conflict');
            }
            if ($commandClosure !== null) {
                $scope = $record->commandScope;
                if ($scope === null || $commandClosure->closure?->isConfirmed() !== true
                    || $commandClosure->processIdentity === null) {
                    return ExecutionGenerationResult::rejected('command closure proof is incomplete');
                }
                $existing = $scope->observation;
                $existingClosure = $existing?->closure;
                if ($existingClosure !== null && $existingClosure->isConfirmed()
                    && !$this->sameClosure($existingClosure, $commandClosure->closure)) {
                    return ExecutionGenerationResult::rejected('conflicting command closure facts');
                }
                $existingIdentity = $existing?->processIdentity;
                if ($existingIdentity !== null
                    && !$this->sameProcessIdentity($existingIdentity, $commandClosure->processIdentity)) {
                    return ExecutionGenerationResult::rejected('conflicting command identity facts');
                }
                $merged = $this->mergeObservation($existing, $commandClosure);
                $record = $record->transition(
                    commandScope: $scope->transition(observation: $merged),
                    updatedAt: max($record->updatedAt, $commandClosure->observedAt),
                );
            }
            if (!$this->attemptClosed($record, intdiv($nowUs, 1_000_000), $nowUs)) {
                return ExecutionGenerationResult::rejected('execution ownership is not fully closed');
            }
            if ($newFence->generation <= $record->fence->generation) {
                return ExecutionGenerationResult::rejected('generation must increase strictly');
            }
            $rotatedRecovery = new ExecutionRecoveryFence('recovery-' . bin2hex(random_bytes(16)), $newFence->generation);
            // Build the revoked old-attempt projection in memory first so the
            // lifecycle guard permits the fresh-generation transition. Publish
            // only the replacement snapshot: no intermediate revoked record can
            // race a terminal outcome or be mistaken for a second redelivery.
            $closed = $record->state === ExecutionLedgerState::REVOKED
                ? $record
                : $this->copy($record, ExecutionLedgerState::REVOKED, intdiv($nowUs, 1_000_000), null, revoked: intdiv($nowUs, 1_000_000));
            $history = [...$closed->retiredGenerations, new ExecutionRetiredGeneration($closed, 'generation-rotation', intdiv($nowUs, 1_000_000))];
            $rotated = $this->copy(
                $closed,
                ExecutionLedgerState::LAUNCH_REQUIRED,
                intdiv($nowUs, 1_000_000),
                null,
                dispatch: null,
                launch: null,
                revoked: null,
                fence: $newFence,
                attempt: $record->attempt + 1,
                claim: null,
                recovery: $rotatedRecovery,
                descriptor: null,
                identity: null,
                attemptStart: null,
                commandScope: null,
                retiredGenerations: $history,
            );
            $this->write($rotated);
            return ExecutionGenerationResult::allocated($newFence, $rotated);
        });
    }

    public function recordLaunchDescriptor(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, ExecutionLaunchDescriptor $descriptor, string $sourceIdentity): ExecutionDispatchResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $expectedFence, $recoveryFence, $descriptor, $sourceIdentity): ExecutionDispatchResult {
            $r = $this->read($occurrence);
            if ($r === null || !$r->fence->equals($expectedFence) || !$this->recoveryMatches($r, $recoveryFence, $sourceIdentity)) {
                return ExecutionDispatchResult::stale();
            }
            if (!in_array($r->state, [ExecutionLedgerState::LAUNCH_REQUESTED, ExecutionLedgerState::LAUNCH_AMBIGUOUS], true) || $r->dispatchReservation === null) {
                return ExecutionDispatchResult::rejected('launch descriptor requires a dispatch reservation');
            }
            if (!ExecutionLedgerRecord::launchDescriptorMatches($descriptor, $r->occurrence, $r->correlation, $r->fence)) {
                return ExecutionDispatchResult::stale('launch descriptor does not match execution');
            }
            if ($r->launchDescriptor !== null && $r->launchDescriptor->marker() !== $descriptor->marker()) {
                return ExecutionDispatchResult::stale('launch descriptor mismatch');
            }
            if ($r->launchDescriptor !== null) {
                return ExecutionDispatchResult::accepted($r);
            }
            $n = $this->copy($r, $r->state, $r->updatedAt, 'keep', descriptor: $descriptor);
            $this->write($n);
            return ExecutionDispatchResult::accepted($n);
        });
    }

    public function reserveDispatch(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionDispatchReservation $reservation): ExecutionDispatchResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $fence, $reservation): ExecutionDispatchResult {
            $r = $this->read($occurrence);
            if ($r === null || !$r->fence->equals($fence)) {
                return ExecutionDispatchResult::stale();
            }
            if ($r->dispatchReservation !== null || $r->state !== ExecutionLedgerState::LAUNCH_REQUIRED || $reservation->launchPhase !== ExecutionDispatchReservation::PREPARED) {
                return ExecutionDispatchResult::alreadyLaunched($r);
            }
            if ($r->recoveryDeadline !== null && ($this->reservationReachesRecoveryDeadline($reservation->reservedAt, $r->recoveryDeadline)
                || $this->reservationReachesRecoveryDeadline($reservation->deadline, $r->recoveryDeadline))) {
                return ExecutionDispatchResult::rejected('reservation reaches recovery deadline');
            }
            $n = $this->copy($r, ExecutionLedgerState::LAUNCH_REQUESTED, $reservation->reservedAt, null, dispatch: $reservation);
            $this->write($n);
            return ExecutionDispatchResult::accepted($n);
        });
    }

    public function redispatchSameGeneration(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, ExecutionDispatchReservation $reservation, int $nowUs, string $sourceIdentity): ExecutionDispatchResult
    {
        $dispatchAt = intdiv($nowUs, 1000000);
        return $this->withLock($occurrence, function () use ($occurrence, $expectedFence, $recoveryFence, $reservation, $nowUs, $dispatchAt, $sourceIdentity): ExecutionDispatchResult {
            $r = $this->read($occurrence);
            if ($r === null || !$r->fence->equals($expectedFence) || !$this->recoveryMatches($r, $recoveryFence, $sourceIdentity)) {
                return ExecutionDispatchResult::stale();
            }
            if ($r->recoveryDeadline !== null && $nowUs >= $r->recoveryDeadline) {
                return ExecutionDispatchResult::rejected('recovery deadline has elapsed');
            }
            if (in_array($r->state, [ExecutionLedgerState::CLAIMED,ExecutionLedgerState::RUNNING], true)) {
                return ExecutionDispatchResult::rejected('claimed execution cannot be redispatched');
            }
            if ($r->isTerminal() || $r->state === ExecutionLedgerState::REVOKED) {
                return ExecutionDispatchResult::stale('terminal or revoked execution');
            }
            if ($r->state !== ExecutionLedgerState::LAUNCH_REQUIRED
                && ($r->state !== ExecutionLedgerState::LAUNCH_REQUESTED || $r->dispatchReservation === null
                    || ($r->dispatchReservation->launchPhase !== ExecutionDispatchReservation::PREPARED
                        && $r->dispatchReservation->deadline > $dispatchAt))) {
                return ExecutionDispatchResult::rejected('execution is not eligible for same-generation redispatch');
            }
            if ($reservation->launchPhase !== ExecutionDispatchReservation::PREPARED
                || $reservation->deadline <= $dispatchAt
                || ($r->dispatchReservation !== null && $r->dispatchReservation->permissionId === $reservation->permissionId)) {
                return ExecutionDispatchResult::rejected('dispatch permission was already consumed');
            }
            $n = $this->copy($r, ExecutionLedgerState::LAUNCH_REQUESTED, $dispatchAt, null, dispatch: $reservation, launch: null, revoked: null);
            $this->write($n);
            return ExecutionDispatchResult::sameGenerationUnclaimed($n);
        });
    }

    public function observeLaunch(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, ExecutionLaunchObservation $observation, string $sourceIdentity): ExecutionDispatchResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $expectedFence, $recoveryFence, $observation, $sourceIdentity): ExecutionDispatchResult {
            $r = $this->read($occurrence);
            if ($r === null || !$r->fence->equals($expectedFence) || !$this->recoveryMatches($r, $recoveryFence, $sourceIdentity)) {
                return ExecutionDispatchResult::stale();
            }
            // Legacy observation remains a facts-only path. It never consumes a
            // prepared/starting permission; asynchronous claim still requires
            // the observed permission produced by launchUnderLock.
            if ($observation->pid !== null && ($observation->processIdentity === null || $observation->pid !== $observation->processIdentity->pid)) {
                return ExecutionDispatchResult::rejected('launch observation PID conflicts with process identity');
            }
            $processIdentity = $observation->processIdentity;
            $observedAttemptStart = $observation->startObservation;
            // Identity-only observations are deliberately incomplete: they do
            // not invent an anchor from admission or observation time.
            $startAnchorIdentity = $r->startAnchorIdentity ?? ($observedAttemptStart !== null ? $processIdentity : null);
            $startObservation = $r->startObservation ?? ($startAnchorIdentity !== null ? $observedAttemptStart : null);
            $attemptStartObservation = $observedAttemptStart ?? $r->attemptStartObservation;
            if ($processIdentity !== null || $observedAttemptStart !== null) {
                if (($r->processIdentity !== null && $processIdentity !== null && !$this->sameProcessIdentity($r->processIdentity, $processIdentity))
                    || ($r->attemptStartObservation !== null && $observedAttemptStart !== null && !$this->sameStartObservation($r->attemptStartObservation, $observedAttemptStart))) {
                    return ExecutionDispatchResult::rejected('conflicting process identity facts');
                }
            }
            if ($r->launchObservation !== null && $this->sameObservation($r->launchObservation, $observation)) {
                return ExecutionDispatchResult::accepted($r);
            }
            $mergedObservation = $this->mergeObservation($r->launchObservation, $observation);
            $processIdentity = $mergedObservation->processIdentity;
            $observedAttemptStart = $mergedObservation->startObservation;
            $startAnchorIdentity = $r->startAnchorIdentity ?? ($observedAttemptStart !== null ? $processIdentity : null);
            $startObservation = $r->startObservation ?? ($startAnchorIdentity !== null ? $observedAttemptStart : null);
            $attemptStartObservation = $observedAttemptStart ?? $r->attemptStartObservation;
            $existingClosure = $r->launchObservation?->closure;
            $incomingClosure = $observation->closure;
            if ($existingClosure !== null && $existingClosure->isConfirmed()
                && $incomingClosure !== null && $incomingClosure->isConfirmed()
                && !$this->sameClosure($existingClosure, $incomingClosure)) {
                return ExecutionDispatchResult::rejected('conflicting process closure facts');
            }
            // Outcomes and claims are authoritative business transitions, but
            // process observations are independent same-generation facts. A
            // late start or closure must therefore survive both transitions.
            if ($r->isTerminal() || $r->state === ExecutionLedgerState::RECOVERY_EXPIRED
                || $r->state === ExecutionLedgerState::REVOKED
                || in_array($r->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING], true)) {
                if ($observation->processIdentity === null && $observation->closure?->isConfirmed() !== true) {
                    return $r->isTerminal() || $r->state === ExecutionLedgerState::RECOVERY_EXPIRED
                        ? ExecutionDispatchResult::accepted($r)
                        : ExecutionDispatchResult::rejected('process facts require a confirmed observation');
                }
                $existing = $r->launchObservation;
                $storedIdentity = $mergedObservation->processIdentity ?? $r->processIdentity;
                // A terminal synchronous record has no recovery window. Keep its
                // process observation as attempt facts instead of manufacturing
                // an original recovery anchor and timing.
                $canAnchor = $r->startAnchorIdentity !== null || $this->recoveryAge($r) !== null;
                $storedAnchorIdentity = $r->startAnchorIdentity ?? ($canAnchor && $observation->startObservation !== null ? $storedIdentity : null);
                $storedStart = $r->startObservation ?? ($canAnchor ? $existing?->startObservation ?? $observation->startObservation : null);
                $storedAttemptStart = $r->attemptStartObservation ?? $mergedObservation->startObservation;
                $mergedObservation = new ExecutionLaunchObservation($mergedObservation->pid, $mergedObservation->error, $mergedObservation->classification, $mergedObservation->observedAt, $mergedObservation->closure, $mergedObservation->launchHandle, $storedIdentity, $storedAttemptStart);
                $anchorAge = $r->recoveryAge ?? ($r->startObservation === null && $storedStart !== null ? $this->recoveryAge($r) : 'keep');
                if ($r->recoveryDeadline === null && $r->startObservation === null && $storedStart !== null) {
                    $anchorDeadline = RecoveryDeadline::forStart($r, $storedStart);
                } else {
                    $anchorDeadline = $r->recoveryDeadline ?? 'keep';
                }
                $completeRootReceipt = $storedIdentity !== null && $storedStart !== null;
                $completeRecoveryFacts = $storedAnchorIdentity !== null && $anchorAge !== null && $anchorDeadline !== null;
                $dispatch = $this->promoteReservation($r->dispatchReservation, $completeRootReceipt && $completeRecoveryFacts);
                $n = $this->copy($r, $r->state, $observation->observedAt, 'keep', dispatch: $dispatch, launch: $mergedObservation, identity: $storedIdentity, anchorIdentity: $storedAnchorIdentity, start: $storedStart, attemptStart: $storedAttemptStart, recoveryAge: $anchorAge, recoveryDeadline: $anchorDeadline);
                $this->write($n);
                return ExecutionDispatchResult::accepted($n);
            }
            if (!in_array($r->state, [ExecutionLedgerState::LAUNCH_REQUESTED,ExecutionLedgerState::LAUNCH_AMBIGUOUS,ExecutionLedgerState::NEEDS_RESOLUTION], true) || $r->dispatchReservation === null) {
                return ExecutionDispatchResult::rejected('launch observation requires a dispatch reservation');
            }
            $age = $startAnchorIdentity === null ? null : $this->recoveryAge($r);
            if ($startAnchorIdentity === null) {
                $deadline = null;
            } else {
                if ($startObservation === null) {
                    throw new InvalidArgumentException('Launch observation start facts are incomplete.');
                }
                $deadline = RecoveryDeadline::forStart($r, $startObservation);
            }
            $reservation = $this->promoteReservation(
                $r->dispatchReservation,
                $mergedObservation->processIdentity !== null && $mergedObservation->startObservation !== null,
            );
            $n = $this->copy($r, ExecutionLedgerState::NEEDS_RESOLUTION, $observation->observedAt, null, dispatch: $reservation, launch: $mergedObservation, identity: $processIdentity, anchorIdentity: $startAnchorIdentity, start: $startObservation, attemptStart: $observedAttemptStart, recoveryAge: $age, recoveryDeadline: $deadline);
            $this->write($n);
            return ExecutionDispatchResult::ambiguous($n);
        });
    }

    public function markRecoveryExpired(ExecutionOccurrenceKey $occurrence, ExecutionFence $expectedFence, ExecutionRecoveryFence $recoveryFence, int $nowUs, string $sourceIdentity): ExecutionRecoveryResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $expectedFence, $recoveryFence, $nowUs, $sourceIdentity): ExecutionRecoveryResult {
            $r = $this->read($occurrence);
            if ($r !== null && ($r->revokedAt !== null || $r->state === ExecutionLedgerState::REVOKED)) {
                return ExecutionRecoveryResult::result(ExecutionRecoveryResult::REVOKED, $r, 'execution is already revoked');
            }
            if ($r === null || !$r->fence->equals($expectedFence) || !$this->recoveryMatches($r, $recoveryFence, $sourceIdentity)) {
                return ExecutionRecoveryResult::result(ExecutionRecoveryResult::STALE);
            }
            if ($r->isTerminal()) {
                return ExecutionRecoveryResult::terminal($r);
            }
            if ($r->state === ExecutionLedgerState::RECOVERY_EXPIRED && $r->recoveryDisposition === ExecutionLedgerState::RECOVERY_EXPIRED) {
                return ExecutionRecoveryResult::expired($r);
            }
            if ($r->recoveryDeadline === null || $nowUs < $r->recoveryDeadline) {
                return ExecutionRecoveryResult::result(ExecutionRecoveryResult::REJECTED, $r, 'recovery deadline has not elapsed');
            }
            $n = $this->copyWithRecoveryDisposition($this->copy($r, ExecutionLedgerState::RECOVERY_EXPIRED, intdiv($nowUs, 1000000), 'keep'), ExecutionLedgerState::RECOVERY_EXPIRED);
            $this->write($n);
            return ExecutionRecoveryResult::expired($n);
        });
    }

    public function recover(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $recoveryFence, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionRecoveryResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $recoveryFence, $nowUs, $expectedFence, $sourceIdentity): ExecutionRecoveryResult {
            $r = $this->read($occurrence);
            if ($r === null || !$r->fence->equals($expectedFence) || !$this->recoveryMatches($r, $recoveryFence, $sourceIdentity)) {
                return ExecutionRecoveryResult::result(ExecutionRecoveryResult::STALE);
            }
            if ($r->isTerminal()) {
                return ExecutionRecoveryResult::terminal($r);
            }
            if ($r->state === ExecutionLedgerState::REVOKED) {
                return ExecutionRecoveryResult::result(ExecutionRecoveryResult::REVOKED, $r);
            }
            if (in_array($r->state, [ExecutionLedgerState::CLAIMED,ExecutionLedgerState::RUNNING], true)) {
                return $r->claimLeaseDeadline !== null && $r->claimLeaseDeadline <= intdiv($nowUs, 1000000) ? ExecutionRecoveryResult::leaseExpiredObserved($r) : ExecutionRecoveryResult::result(ExecutionRecoveryResult::REJECTED, $r, 'claim lease is still live');
            }
            if ($r->recoveryDeadline !== null && $nowUs >= $r->recoveryDeadline) {
                return ExecutionRecoveryResult::result(ExecutionRecoveryResult::RECOVERY_EXPIRED, $r, 'recovery deadline has elapsed');
            }
            if ($r->state === ExecutionLedgerState::LAUNCH_REQUIRED
                || ($r->state === ExecutionLedgerState::LAUNCH_REQUESTED
                    && $r->dispatchReservation !== null
                    && $r->dispatchReservation->launchPhase === ExecutionDispatchReservation::PREPARED
                    && $r->dispatchReservation->deadline <= intdiv($nowUs, 1000000))) {
                return ExecutionRecoveryResult::sameGenerationUnclaimed($r);
            }
            return ExecutionRecoveryResult::needsResolution($r);
        });
    }

    public function revoke(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $recoveryFence, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionRevocationResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $recoveryFence, $nowUs, $expectedFence, $sourceIdentity): ExecutionRevocationResult {
            $dispatchAt = intdiv($nowUs, 1000000);
            $r = $this->read($occurrence);
            if ($r === null || !$r->fence->equals($expectedFence) || !$this->recoveryMatches($r, $recoveryFence, $sourceIdentity)) {
                return ExecutionRevocationResult::stale();
            }
            if ($r->isTerminal()) {
                return ExecutionRevocationResult::rejected('terminal outcome is authoritative');
            }
            if ($r->revokedAt !== null) {
                return ExecutionRevocationResult::alreadyRevoked($r);
            }
            if ($r->state !== ExecutionLedgerState::LAUNCH_REQUIRED && !$this->hasConfirmedClosure($r)) {
                return ExecutionRevocationResult::rejected('process closure is not confirmed');
            }
            $n = $this->copy($r, ExecutionLedgerState::REVOKED, $dispatchAt, null, revoked: $dispatchAt);
            $this->write($n);
            return ExecutionRevocationResult::accepted($n);
        });
    }

    public function allocateGeneration(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $recoveryFence, ExecutionFence $newFence, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionGenerationResult
    {
        return $this->withLock(
            $occurrence,
            fn(): ExecutionGenerationResult => $this->allocateGenerationUnderLock($occurrence, $recoveryFence, $newFence, $nowUs, $expectedFence, $sourceIdentity),
        );
    }

    public function recordOutcome(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionOutcome $outcome, int $at): ExecutionTerminalResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $fence, $outcome, $at): ExecutionTerminalResult {
            $r = $this->read($occurrence);
            if ($r === null) {
                return ExecutionTerminalResult::missing();
            }
            if (!$r->fence->equals($fence) || $r->state === ExecutionLedgerState::REVOKED || $r->revokedAt !== null) {
                return ExecutionTerminalResult::staleFenced();
            }
            if ($r->isTerminal()) {
                return ExecutionTerminalResult::alreadyTerminal();
            }
            // A launch observer may conservatively move a claimed execution to
            // needs-resolution while the fresh worker is still preparing its
            // process-local runtime. The worker's fenced terminal outcome is
            // authoritative once its claim has succeeded; do not leave a
            // completed callable stranded behind that observation race.
            if (!in_array($r->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING, ExecutionLedgerState::NEEDS_RESOLUTION, ExecutionLedgerState::RECOVERY_EXPIRED], true)) {
                return ExecutionTerminalResult::rejected('terminal outcome requires a claim or running transition');
            }
            if (($r->state === ExecutionLedgerState::NEEDS_RESOLUTION || $r->state === ExecutionLedgerState::RECOVERY_EXPIRED)
                && ($r->claimLeaseDeadline === null || $at >= $r->claimLeaseDeadline)) {
                return ExecutionTerminalResult::rejected('accepted claim lease is absent or expired');
            }
            if ($r->state !== ExecutionLedgerState::RECOVERY_EXPIRED
                && $r->claimLeaseDeadline !== null && $at >= $r->claimLeaseDeadline) {
                return ExecutionTerminalResult::rejected('claim lease has expired');
            }
            $n = $this->copy($r, $outcome->status, $at, $outcome, claim: null);
            $this->write($n);
            return ExecutionTerminalResult::accepted($n);
        });
    }

    private function hasCompleteWorkerLaunchReceipt(ExecutionLedgerRecord $record, ?ExecutionLaunchDescriptor $descriptor, ?string $permissionId): bool
    {
        if ($descriptor === null || $permissionId === null) {
            return false;
        }
        $dispatchReservation = $record->dispatchReservation;
        return $dispatchReservation !== null
            && $dispatchReservation->permissionId === $permissionId
            && $dispatchReservation->launchPhase === ExecutionDispatchReservation::OBSERVED
            && $record->launchDescriptor !== null
            && $record->launchDescriptor->equals($descriptor)
            && $record->launchObservation !== null
            && $record->launchObservation->processIdentity !== null
            && ($record->launchObservation->pid === null || $record->launchObservation->pid === $record->launchObservation->processIdentity->pid)
            && $record->launchObservation->startObservation !== null
            && $record->startAnchorIdentity !== null
            && $record->startObservation !== null
            && $record->recoveryDeadline !== null;
    }

    private function allocateGenerationUnderLock(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $recoveryFence, ExecutionFence $newFence, int $nowUs, ExecutionFence $expectedFence, string $sourceIdentity): ExecutionGenerationResult
    {
        $r = $this->read($occurrence);
        if ($r === null || !$r->fence->equals($expectedFence) || !$this->recoveryMatches($r, $recoveryFence, $sourceIdentity)) {
            return ExecutionGenerationResult::stale();
        }
        if ($r->isTerminal()) {
            return ExecutionGenerationResult::rejected('terminal outcome is authoritative');
        }
        if ($r->revokedAt === null) {
            return ExecutionGenerationResult::requiresRevocation($r);
        }
        if (!$this->attemptClosed($r, intdiv($nowUs, 1_000_000), $nowUs)) {
            return ExecutionGenerationResult::rejected('execution ownership is not fully closed');
        }
        if ($r->recoveryDeadline !== null && $nowUs >= $r->recoveryDeadline) {
            return ExecutionGenerationResult::rejected('recovery deadline has elapsed');
        }
        if (in_array($r->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING, ExecutionLedgerState::LAUNCH_AMBIGUOUS, ExecutionLedgerState::NEEDS_RESOLUTION], true) && !$this->hasConfirmedClosure($r)) {
            return ExecutionGenerationResult::rejected('process closure is not confirmed');
        }
        if ($newFence->generation <= $r->fence->generation) {
            return ExecutionGenerationResult::rejected('generation must increase strictly');
        }
        $rotatedRecovery = new ExecutionRecoveryFence('recovery-' . bin2hex(random_bytes(16)), $newFence->generation);
        // Retain the complete old attempt only at retirement. Ordinary observations
        // stay in the current record; recovery never scans this diagnostic history.
        $history = [...$r->retiredGenerations, new ExecutionRetiredGeneration($r, 'generation-rotation', intdiv($nowUs, 1000000))];
        // Keep the immutable recovery anchor/deadline. Per-attempt launch state is
        // replaced independently for the new generation.
        $n = $this->copy($r, ExecutionLedgerState::LAUNCH_REQUIRED, intdiv($nowUs, 1000000), null, fence: $newFence, attempt: $r->attempt + 1, dispatch: null, launch: null, revoked: null, claim: null, recovery: $rotatedRecovery, descriptor: null, identity: null, attemptStart: null, commandScope: null, retiredGenerations: $history);
        $this->write($n);
        return ExecutionGenerationResult::allocated($newFence, $n);
    }

    /** @return list<ExecutionLedgerRecord> */
    private function sqlList(string $source, string $where): array
    {
        $sql = 'SELECT task_key, occurrence_id, source_identity, payload FROM execution_ledger WHERE ' . $where;
        $params = [];
        if ($source !== '') {
            $sql = 'SELECT task_key, occurrence_id, source_identity, payload FROM execution_ledger WHERE source_identity = :source AND ' . $where;
            $params = ['source' => $source];
        }
        $rows = $this->connection->createCommand($sql . ' ORDER BY task_key, occurrence_id', $params)->queryAll();
        /** @var list<array<string,mixed>> $rows */
        $records = [];
        foreach ($rows as $row) {
            $records[] = $this->decodeRow($row);
        }
        return $records;
    }

    /** @param array<string,mixed> $row */
    private function decodeRow(array $row): ExecutionLedgerRecord
    {
        $record = $this->codec->decode((string) ($row['payload'] ?? ''));
        if (($row['task_key'] ?? null) !== $record->occurrence->taskKey
            || ($row['occurrence_id'] ?? null) !== $record->occurrence->occurrenceId
            || ($row['source_identity'] ?? null) !== $record->sourceIdentity) {
            throw new InvalidArgumentException('SQLite execution ledger row identity does not match its payload.');
        }
        return $record;
    }

    /** @return array{0:string,1:string} */
    private function decodeKeysetCursor(string $sourceIdentity, string $cursor): array
    {
        if ($cursor === '' || strlen($cursor) > 1024 || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
            throw new InvalidArgumentException('Recovery cursor is invalid.');
        }
        $padding = (4 - strlen($cursor) % 4) % 4;
        $raw = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', $padding), true);
        if ($raw === false || $this->cursorJsonHasInvalidObject($raw)) {
            throw new InvalidArgumentException('Recovery cursor is invalid.');
        }
        try {
            $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Recovery cursor is invalid.', 0, $e);
        }
        if (!is_array($value) || array_keys($value) !== ['source', 'task', 'occurrence']) {
            throw new InvalidArgumentException('Recovery cursor is invalid or stale.');
        }
        $encodedSource = $value['source'] ?? null;
        $task = $value['task'] ?? null;
        $occurrence = $value['occurrence'] ?? null;
        if (!is_string($encodedSource) || !is_string($task) || !is_string($occurrence)
            || !hash_equals(hash('sha256', $sourceIdentity), $encodedSource)
            || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $cursor
            || $task === '' || $occurrence === '') {
            throw new InvalidArgumentException('Recovery cursor is invalid or stale.');
        }
        return [$task, $occurrence];
    }

    private function encodeKeysetCursor(string $sourceIdentity, ExecutionOccurrenceKey $occurrence): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            'source' => hash('sha256', $sourceIdentity),
            'task' => $occurrence->taskKey,
            'occurrence' => $occurrence->occurrenceId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    private function cursorJsonHasInvalidObject(string $json): bool
    {
        $offset = 0;
        return $this->cursorJsonScanValue($json, $offset);
    }

    private function cursorJsonScanValue(string $json, int &$offset): bool
    {
        $length = strlen($json);
        while ($offset < $length && ($json[$offset] === ' ' || $json[$offset] === "\\t" || $json[$offset] === "\\r" || $json[$offset] === "\\n")) {
            $offset++;
        }
        if ($offset >= $length) {
            return true;
        }
        if ($json[$offset] === '"') {
            $offset++;
            while ($offset < $length) {
                if ($json[$offset] === '\\\\') {
                    $offset += 2;
                    continue;
                }
                if ($json[$offset++] === '"') {
                    return false;
                }
            }
            return true;
        }
        if ($json[$offset] === '{') {
            $offset++;
            $keys = [];
            while (true) {
                while ($offset < $length && ($json[$offset] === ' ' || $json[$offset] === "\\t" || $json[$offset] === "\\r" || $json[$offset] === "\\n")) {
                    $offset++;
                }
                if ($offset >= $length) {
                    return true;
                }
                if ($json[$offset] === '}') {
                    $offset++;
                    break;
                }
                if ($json[$offset] !== '"') {
                    return true;
                }
                $start = $offset;
                if ($this->cursorJsonScanValue($json, $offset)) {
                    return true;
                }
                $key = json_decode(substr($json, $start, $offset - $start), true);
                if (!is_string($key) || isset($keys['#' . $key])) {
                    return true;
                }
                $keys['#' . $key] = true;
                while ($offset < $length && ($json[$offset] === ' ' || $json[$offset] === "\\t" || $json[$offset] === "\\r" || $json[$offset] === "\\n")) {
                    $offset++;
                }
                if ($offset >= $length || $json[$offset++] !== ':') {
                    return true;
                }
                if ($this->cursorJsonScanValue($json, $offset)) {
                    return true;
                }
                while ($offset < $length && ($json[$offset] === ' ' || $json[$offset] === "\\t" || $json[$offset] === "\\r" || $json[$offset] === "\\n")) {
                    $offset++;
                }
                if ($offset >= $length) {
                    return true;
                }
                if ($json[$offset] === ',') {
                    $offset++;
                    continue;
                }
                if ($json[$offset] === '}') {
                    $offset++;
                    break;
                }
                return true;
            }
            $keysList = array_keys($keys);
            foreach ($keysList as $index => $key) {
                $key = substr($key, 1);
                if ($key !== (string) $index) {
                    return false;
                }
            }
            return $keysList !== [];
        }
        if ($json[$offset] === '[') {
            $offset++;
            while (true) {
                while ($offset < $length && ($json[$offset] === ' ' || $json[$offset] === "\\t" || $json[$offset] === "\\r" || $json[$offset] === "\\n")) {
                    $offset++;
                }
                if ($offset >= $length) {
                    return true;
                }
                if ($json[$offset] === ']') {
                    $offset++;
                    return false;
                }
                if ($this->cursorJsonScanValue($json, $offset)) {
                    return true;
                }
                while ($offset < $length && ($json[$offset] === ' ' || $json[$offset] === "\\t" || $json[$offset] === "\\r" || $json[$offset] === "\\n")) {
                    $offset++;
                }
                if ($offset < $length && $json[$offset] === ',') {
                    $offset++;
                    continue;
                }
                if ($offset < $length && $json[$offset] === ']') {
                    $offset++;
                    return false;
                }
                return true;
            }
        }
        while ($offset < $length && !in_array($json[$offset], [',', ']', '}'], true)) {
            $offset++;
        }
        return false;
    }

    private function reservationReachesRecoveryDeadline(int $reservedAt, int $deadlineUs): bool
    {
        // Compare in quotient/remainder form: multiplying seconds by a
        // million could overflow even though both persisted values are valid.
        if ($deadlineUs <= 0) {
            return true;
        }
        $deadlineSeconds = intdiv($deadlineUs, 1_000_000);
        // The dispatch deadline is exclusive. Equality is safe when the
        // recovery deadline is exact-second because no launch may occur at
        // that boundary; only a reservation extending beyond it is invalid.
        return $reservedAt > $deadlineSeconds;
    }

    private function copy(ExecutionLedgerRecord $r, string $state, int $at, ExecutionOutcome|string|null $out, ExecutionDispatchReservation|string|null $dispatch = 'keep', ExecutionLaunchObservation|string|null $launch = 'keep', int|string|null $revoked = 'keep', ExecutionFence|string $fence = 'keep', int|string $attempt = 'keep', int|string|null $claim = 'keep', ExecutionRecoveryFence|string|null $recovery = 'keep', ExecutionLaunchDescriptor|string|null $descriptor = 'keep', ExecutionProcessIdentity|string|null $identity = 'keep', ExecutionProcessIdentity|string|null $anchorIdentity = 'keep', ExecutionStartObservation|string|null $start = 'keep', ExecutionStartObservation|string|null $attemptStart = 'keep', float|string|null $recoveryAge = 'keep', int|string|null $recoveryDeadline = 'keep', ExecutionCommandScope|string|null $commandScope = 'keep', array|string $retiredGenerations = 'keep'): ExecutionLedgerRecord
    {
        return $r->transition(
            state: $state,
            updatedAt: $at,
            outcome: $out,
            dispatchReservation: $dispatch,
            launchObservation: $launch,
            revokedAt: $revoked,
            fence: $fence,
            attempt: $attempt,
            claimLeaseDeadline: $claim,
            recoveryFence: $recovery,
            launchDescriptor: $descriptor,
            processIdentity: $identity,
            startAnchorIdentity: $anchorIdentity,
            startObservation: $start,
            attemptStartObservation: $attemptStart,
            recoveryAge: $recoveryAge,
            recoveryDeadline: $recoveryDeadline,
            commandScope: $commandScope,
            retiredGenerations: $retiredGenerations,
        );
    }

    private function copyWithRecoveryDisposition(ExecutionLedgerRecord $r, string $disposition): ExecutionLedgerRecord
    {
        return $r->transition(recoveryDisposition: $disposition, updatedAt: $r->updatedAt);
    }

    private function recoveryAge(ExecutionLedgerRecord $record): ?float
    {
        $value = $record->taskSnapshot['task']['recoveryMaxAge'] ?? null;
        return is_float($value) && is_finite($value) && $value > 0 ? $value : null;
    }

    private function recoveryMatches(ExecutionLedgerRecord $record, ExecutionRecoveryFence $fence, string $sourceIdentity): bool
    {
        return $record->sourceIdentity === $sourceIdentity
            && $record->recoveryFence !== null
            && $record->recoveryFence->equals($fence)
            && $fence->generation === $record->fence->generation;
    }

    private function promoteReservation(?ExecutionDispatchReservation $reservation, bool $complete): ?ExecutionDispatchReservation
    {
        if ($reservation === null) {
            return null;
        }
        if (!$complete) {
            return in_array($reservation->launchPhase, [ExecutionDispatchReservation::PREPARED, ExecutionDispatchReservation::STARTING], true)
                ? $reservation->withPhase(ExecutionDispatchReservation::AMBIGUOUS)
                : $reservation;
        }
        // Only launchUnderLock has consumed STARTING and validated a complete
        // callback receipt. An observer can never turn a prepared permission
        // into a claimable OBSERVED permission.
        return in_array($reservation->launchPhase, [ExecutionDispatchReservation::PREPARED, ExecutionDispatchReservation::STARTING, ExecutionDispatchReservation::AMBIGUOUS], true)
            ? $reservation->withPhase(ExecutionDispatchReservation::AMBIGUOUS)
            : $reservation;
    }

    private function mergeClosedRoot(ExecutionLedgerRecord $record, ExecutionLaunchObservation $observation): ?ExecutionLedgerRecord
    {
        if ($observation->pid !== null && ($observation->processIdentity === null || $observation->pid !== $observation->processIdentity->pid)) {
            return null;
        }
        $existing = $record->launchObservation;
        $existingClosure = $existing?->closure;
        if ($existingClosure !== null && $existingClosure->isConfirmed()
            && $observation->closure?->isConfirmed() === true
            && !$this->sameClosure($existingClosure, $observation->closure)) {
            return null;
        }
        if ($record->processIdentity !== null && $observation->processIdentity !== null
            && !$this->sameProcessIdentity($record->processIdentity, $observation->processIdentity)) {
            return null;
        }
        $merged = $this->mergeObservation($existing, $observation);
        $identity = $record->processIdentity ?? $merged->processIdentity;
        $start = $record->startObservation ?? $merged->startObservation;
        $attemptStart = $record->attemptStartObservation ?? $merged->startObservation;
        $anchor = $record->startAnchorIdentity;
        $age = $record->recoveryAge;
        $deadline = $record->recoveryDeadline;
        if ($anchor === null && $age !== null && $start !== null) {
            $anchor = $identity;
        }
        if ($deadline === null && $start !== null && $anchor !== null) {
            $deadline = RecoveryDeadline::forStart($record, $start);
        }
        return $this->copy(
            $record,
            $record->state,
            max($record->updatedAt, $observation->observedAt),
            'keep',
            launch: $merged,
            identity: $identity,
            anchorIdentity: $anchor,
            start: $start,
            attemptStart: $attemptStart,
            recoveryAge: $age,
            recoveryDeadline: $deadline,
        );
    }

    private function hasConfirmedClosure(ExecutionLedgerRecord $r): bool
    {
        return $r->launchObservation?->closure?->isConfirmed() ?? false;
    }

    private function sameProcessIdentity(ExecutionProcessIdentity $left, ExecutionProcessIdentity $right): bool
    {
        return $left->pid === $right->pid && $left->processGroup === $right->processGroup
            && $left->session === $right->session && $left->startTicks === $right->startTicks
            && $left->bootId === $right->bootId;
    }

    private function sameStartObservation(ExecutionStartObservation $left, ExecutionStartObservation $right): bool
    {
        return $left->wallMicroseconds === $right->wallMicroseconds
            && $left->monotonicNanoseconds === $right->monotonicNanoseconds;
    }

    private function sameClosure(ExecutionProcessClosure $left, ExecutionProcessClosure $right): bool
    {
        return $left->status === $right->status
            && ($left->exitCode === $right->exitCode || $left->exitCode === null || $right->exitCode === null);
    }

    private function mergeClosure(?ExecutionProcessClosure $existing, ?ExecutionProcessClosure $incoming): ?ExecutionProcessClosure
    {
        if ($existing === null) {
            return $incoming;
        }
        if ($incoming === null || ($existing->isConfirmed() && !$incoming->isConfirmed())) {
            return $existing;
        }
        if (!$existing->isConfirmed() && $incoming->isConfirmed()) {
            return $incoming;
        }
        // Status and exit code are authoritative; reason is diagnostic. If the
        // incoming observation supplies the previously missing exit code, keep
        // that complete observation; otherwise retain the first observation.
        return $existing->exitCode === null && $incoming->exitCode !== null ? $incoming : $existing;
    }

    private function mergeObservation(?ExecutionLaunchObservation $existing, ExecutionLaunchObservation $incoming): ExecutionLaunchObservation
    {
        $closure = $this->mergeClosure($existing?->closure, $incoming->closure);
        return new ExecutionLaunchObservation(
            $incoming->pid ?? $existing?->pid,
            $incoming->error,
            $incoming->classification,
            max($existing?->observedAt ?? 0, $incoming->observedAt),
            $closure,
            $incoming->launchHandle ?? $existing?->launchHandle,
            $incoming->processIdentity ?? $existing?->processIdentity,
            $incoming->startObservation ?? $existing?->startObservation,
        );
    }

    private function sameObservation(ExecutionLaunchObservation $left, ExecutionLaunchObservation $right): bool
    {
        $leftClosure = $left->closure;
        $rightClosure = $right->closure;
        return $left->pid === $right->pid && $left->error === $right->error
            && $left->classification === $right->classification && $left->observedAt === $right->observedAt
            && (($leftClosure === null && $rightClosure === null)
                || ($leftClosure !== null && $rightClosure !== null && $this->sameClosure($leftClosure, $rightClosure)))
            && (($left->processIdentity === null && $right->processIdentity === null)
                || ($left->processIdentity !== null && $right->processIdentity !== null && $this->sameProcessIdentity($left->processIdentity, $right->processIdentity)))
            && (($left->startObservation === null && $right->startObservation === null)
                || ($left->startObservation !== null && $right->startObservation !== null && $this->sameStartObservation($left->startObservation, $right->startObservation)));
    }

    private function isActive(ExecutionLedgerRecord $record): bool
    {
        return !$record->isTerminal() && $record->state !== ExecutionLedgerState::REVOKED;
    }

    private function holdsOwnership(ExecutionLedgerRecord $record): bool
    {
        // A synchronous terminal record that never acquired any process scope
        // has nothing left to reconcile. Do not retain it as an owner forever.
        if ($record->isTerminal()
            && ($record->taskSnapshot['task']['mode'] ?? null) === 'sync'
            && $record->processIdentity === null
            && $record->launchDescriptor === null
            && $record->commandScope === null
        ) {
            return false;
        }
        // A command is a separately owned process scope. Terminal root state
        // therefore does not release the occurrence while that scope remains
        // consumed or merely uncertain. Identity-only, never-claimed facts
        // remain indexed until the fenced pre-start repair consumes them.
        if (!in_array($record->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING], true)
            && $record->startAnchorIdentity === null
            && $record->startObservation === null
            && $record->launchObservation?->startObservation === null) {
            return true;
        }
        return !$this->attemptClosed($record, $record->updatedAt, $record->recoveryDeadline ?? PHP_INT_MAX);
    }

    private function commandPermissionValid(
        ExecutionLedgerRecord $record,
        ?ExecutionCommandScope $scope,
        ExecutionLaunchDescriptor $rootDescriptor,
        int $at,
        int $nowUs,
    ): bool {
        return $scope !== null
            && $scope->reservation->launchPhase === ExecutionDispatchReservation::PREPARED
            && $scope->descriptor->rootDescriptor?->equals($rootDescriptor)
            && $scope->descriptor->occurrence?->equals($record->occurrence)
            && $scope->descriptor->generation === $record->fence->generation
            && $record->launchDescriptor?->equals($rootDescriptor)
            && in_array($record->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING], true)
            && ($record->claimLeaseDeadline === null || $at < $record->claimLeaseDeadline)
            && !$record->isTerminal()
            && $record->revokedAt === null
            && ($record->recoveryDeadline === null || $nowUs < $record->recoveryDeadline)
            && !$this->hasConfirmedClosure($record)
            && $scope->reservation->deadline > $at;
    }

    private function commandScopeClosed(ExecutionLedgerRecord $record, int $at, int $nowUs): bool
    {
        $scope = $record->commandScope;
        if ($scope === null || $scope->observation?->closure?->isConfirmed() === true) {
            return true;
        }
        // Only an unconsumed permission can be invalidated by root closure.
        // A consumed starting/observed/ambiguous scope remains owned until a
        // concrete closure fact arrives; descriptor-only absence is not proof.
        return $scope->reservation->launchPhase === ExecutionDispatchReservation::PREPARED
            && ($record->isTerminal() || $record->revokedAt !== null
                || $this->hasConfirmedClosure($record)
                || ($record->recoveryDeadline !== null && $nowUs >= $record->recoveryDeadline));
    }

    private function attemptClosed(ExecutionLedgerRecord $record, int $at, int $nowUs): bool
    {
        // A terminal pre-start synchronous attempt has no process scope to
        // observe. Its durable outcome is the complete ownership proof; do not
        // strand it in the ownership index waiting for a fabricated closure.
        if ($record->isTerminal()
            && $record->launchDescriptor === null
            && $record->launchObservation === null
            && $record->processIdentity === null
            && $record->commandScope === null) {
            return true;
        }
        // An identity-only observation is diagnostic evidence, not a start
        // anchor. Keep an unclaimed pre-start record recoverable after its
        // observed scope closes; its provenance must survive until the fenced
        // repair consumes the old permission.
        if (!in_array($record->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING], true)
            && $record->startAnchorIdentity === null
            && $record->startObservation === null
            && $record->launchObservation?->startObservation === null) {
            return false;
        }
        return $this->hasConfirmedClosure($record)
            && $this->commandScopeClosed($record, $at, $nowUs)
            && ($record->commandScope === null || $record->launchDescriptor === null
                || !$this->commandPermissionValid($record, $record->commandScope, $record->launchDescriptor, $at, $nowUs));
    }

    /** @return array{taskKey:string,occurrenceId:string} */
    private function activeEntry(ExecutionLedgerRecord $record): array
    {
        return ['taskKey' => $record->occurrence->taskKey, 'occurrenceId' => $record->occurrence->occurrenceId];
    }

    /** @return array{taskKey:string} */
    private function historyEntry(ExecutionLedgerRecord $record): array
    {
        return ['taskKey' => $record->occurrence->taskKey];
    }

    /**
     * @param callable(
     *     list<array{taskKey:string,occurrenceId:string}>&,
     *     list<array{taskKey:string,occurrenceId:string}>&,
     *     list<array{taskKey:string}>&
     * ): void $change
     */
    private function read(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
    {
        $row = $this->connection->createCommand(
            'SELECT task_key, occurrence_id, source_identity, payload FROM execution_ledger WHERE task_key = :task AND occurrence_id = :occurrence',
            ['task' => $occurrence->taskKey, 'occurrence' => $occurrence->occurrenceId],
        )->queryOne();
        if ($row === null) {
            return null;
        }
        $record = $this->decodeRow($row);
        if (!$record->occurrence->equals($occurrence)) {
            throw new InvalidArgumentException('SQLite execution ledger identity mismatch.');
        }
        return $record;
    }

    private function write(ExecutionLedgerRecord $record): void
    {
        $this->connection->createCommand(
            'INSERT INTO execution_ledger (task_key, occurrence_id, source_identity, state, active, ownership, payload) VALUES (:task, :occurrence, :source, :state, :active, :ownership, :payload) ON CONFLICT(task_key, occurrence_id) DO UPDATE SET source_identity = excluded.source_identity, state = excluded.state, active = excluded.active, ownership = excluded.ownership, payload = excluded.payload',
            [
                'task' => $record->occurrence->taskKey,
                'occurrence' => $record->occurrence->occurrenceId,
                'source' => $record->sourceIdentity,
                'state' => $record->state,
                'active' => $this->isActive($record) ? 1 : 0,
                'ownership' => $this->holdsOwnership($record) ? 1 : 0,
                'payload' => $this->codec->encode($record),
            ],
        )->execute();
    }

    private function lock(): void
    {
        $updated = $this->connection->createCommand(
            "UPDATE execution_ledger_lock SET touched_at = :touched WHERE lock_name = 'ledger'",
            ['touched' => time()],
        )->execute();
        if ($updated !== 1) {
            throw new InvalidArgumentException('SQLite execution ledger lock row is missing; repair the database or use a new one.');
        }
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withLock(ExecutionOccurrenceKey $occurrence, callable $fn): mixed
    {
        return $this->connection->transaction(function () use ($fn) {
            $this->lock();
            return $fn();
        });
    }

    private function ensureIndexes(): void
    {
        $this->connection->createCommand('CREATE INDEX IF NOT EXISTS execution_ledger_active ON execution_ledger (source_identity, active, task_key, occurrence_id)')->execute();
        $this->connection->createCommand('CREATE INDEX IF NOT EXISTS execution_ledger_ownership ON execution_ledger (source_identity, ownership, task_key, occurrence_id)')->execute();
    }

    private function validateSqliteSchema(bool $requireIndexes = true): void
    {
        $expected = [
            'execution_ledger_schema' => [['schema_marker', 'TEXT', 1, 0]],
            'execution_ledger' => [
                ['task_key', 'TEXT', 1, 1], ['occurrence_id', 'TEXT', 1, 2],
                ['source_identity', 'TEXT', 1, 0], ['state', 'TEXT', 1, 0],
                ['active', 'INTEGER', 1, 0], ['ownership', 'INTEGER', 1, 0], ['payload', 'TEXT', 1, 0],
            ],
            'execution_ledger_lock' => [['lock_name', 'TEXT', 1, 1], ['touched_at', 'INTEGER', 1, 0]],
        ];
        foreach ($expected as $table => $columns) {
            $actual = $this->connection->createCommand('PRAGMA table_info(' . $table . ')')->queryAll();
            /** @var list<array<string,mixed>> $actual */
            $shape = array_map(static fn(array $row): array => [
                (string) ($row['name'] ?? ''), strtoupper((string) ($row['type'] ?? '')),
                (int) ($row['notnull'] ?? 0), (int) ($row['pk'] ?? 0),
            ], $actual);
            if ($shape !== $columns) {
                throw new InvalidArgumentException("SQLite ledger table $table has incompatible shape; use a new database (migrations are not supported).");
            }
        }
        $markers = $this->connection->createCommand('SELECT schema_marker FROM execution_ledger_schema')->queryAll();
        /** @var list<array<string,mixed>> $markers */
        if (count($markers) !== 1 || ($markers[0]['schema_marker'] ?? null) !== self::SCHEMA) {
            throw new InvalidArgumentException('SQLite execution ledger schema marker is incompatible; expected exactly one dev marker.');
        }
        $lockRows = $this->connection->createCommand("SELECT lock_name FROM execution_ledger_lock WHERE lock_name = 'ledger'")->queryAll();
        /** @var list<array<string,mixed>> $lockRows */
        if (count($lockRows) !== 1) {
            throw new InvalidArgumentException('SQLite execution ledger lock row is missing or duplicated.');
        }
        $uniqueMarker = false;
        /** @var list<array<string,mixed>> $indexes */
        $indexes = $this->connection->createCommand('PRAGMA index_list(execution_ledger_schema)')->queryAll();
        foreach ($indexes as $index) {
            if ((int) ($index['unique'] ?? 0) !== 1) {
                continue;
            }
            $indexName = (string) ($index['name'] ?? '');
            /** @var list<array<string,mixed>> $indexColumns */
            $indexColumns = $this->connection->createCommand('PRAGMA index_info(' . $indexName . ')')->queryAll();
            $columns = array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $indexColumns);
            if ($columns === ['schema_marker']) {
                $uniqueMarker = true;
                break;
            }
        }
        if (!$uniqueMarker) {
            throw new InvalidArgumentException('SQLite execution ledger schema marker must be unique.');
        }
        if (!$requireIndexes) {
            return;
        }
        foreach ([
            'execution_ledger_active' => ['source_identity', 'active', 'task_key', 'occurrence_id'],
            'execution_ledger_ownership' => ['source_identity', 'ownership', 'task_key', 'occurrence_id'],
        ] as $name => $columns) {
            $found = false;
            /** @var list<array<string,mixed>> $indexes */
            $indexes = $this->connection->createCommand('PRAGMA index_list(execution_ledger)')->queryAll();
            foreach ($indexes as $index) {
                if (($index['name'] ?? null) !== $name) {
                    continue;
                }
                /** @var list<array<string,mixed>> $indexColumns */
                $indexColumns = $this->connection->createCommand('PRAGMA index_info(' . $name . ')')->queryAll();
                $actual = array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $indexColumns);
                if ($actual !== $columns) {
                    throw new InvalidArgumentException("SQLite ledger index $name has incompatible shape; use a new database.");
                }
                $found = true;
            }
            if (!$found) {
                throw new InvalidArgumentException("SQLite ledger index $name is missing; call repairIndexes().");
            }
        }
    }
}
