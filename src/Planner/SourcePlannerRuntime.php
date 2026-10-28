<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Psr\Clock\ClockInterface;
use Throwable;
use Yiisoft\Scheduler\Config\{ConfigurationSourceInterface,PreparedConfiguration};
use Yiisoft\Scheduler\Trigger\TriggerFactory;
use Yiisoft\Scheduler\Executor\ExecutionContext;
use Yiisoft\Scheduler\ExecutionMode;
use Yiisoft\Scheduler\Integration\Async\{ExecutionAggregateRedeliveryInterface,ExecutionDispatchReservation,ExecutionIntegrationCoordinator,ExecutionOccurrenceSource,ExecutionOutcome,ExecutionWorkerCapability,ExecutionWorkerLauncherInterface,ExecutionLedgerCommandInterface,ExecutionLedgerQueryInterface,ExecutionLedgerState,ExecutionOccurrenceCheckpointInterface,MonotonicClockInterface,PollWaiterInterface,WorkerLifecyclePolicy,ExecutionRecoveryResult,ExecutionGenerationResult};
use Yiisoft\Scheduler\Integration\Async\Recovery\{RecoveryAction,RecoveryClassifier,RecoveryObservation,RecoveryProcessObserverInterface};
use Yiisoft\Scheduler\Integration\Async\ExecutionFence;
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchObservation;
use Yiisoft\Scheduler\Integration\Async\PreparedConfigurationExecutionBindingSource;
use Yiisoft\Scheduler\Integration\Async\OccurrenceSkipReason;
use RuntimeException;
use Yiisoft\Scheduler\Integration\Async\ExecutionDispatchResult;
use Yiisoft\Scheduler\Integration\Async\ExecutionLedgerRecord;
use Yiisoft\Scheduler\Integration\Async\Process\{LinuxProcessObserver,ProcessLookupResult,ProcessScopeProof};
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchDescriptor;
use Yiisoft\Scheduler\Integration\Async\{ExecutionProcessClosure,ExecutionProcessIdentity};

use function count;
use function ctype_digit;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function bin2hex;
use function random_bytes;

use const PHP_INT_MAX;
use const STR_PAD_LEFT;

/** Concrete parent-side composition for one owned, pinned configuration source. */
final class SourcePlannerRuntime
{
    /** Recovery is deliberately small: one record page and at most this much work per tick. */
    private const RECOVERY_PAGE_LIMIT = 1;
    private const RECOVERY_WORK_BUDGET = 32;
    private const RECOVERY_RETRY_BASE_US = 1000000;
    private const RECOVERY_RETRY_CAP_US = 60000000;

    private readonly ExecutionLedgerCommandInterface $ledger;
    private readonly ExecutionLedgerQueryInterface $query;
    private readonly ExecutionOccurrenceCheckpointInterface $checkpoint;
    /** @var array<string, ExecutionIntegrationCoordinator> */
    private array $coordinators = [];
    /** @var array<string, ExecutionOccurrenceSource> */
    private array $sources = [];
    /** @var array<string, array{candidate: object, capability: ExecutionWorkerCapability}> */
    private array $launches = [];
    private bool $admissionOpen = true;
    private PlannerHealthSnapshot $healthSnapshot;
    private readonly int $healthStartedAt;
    private readonly string $pinnedSourceId;
    private readonly string $pinnedRevision;
    private readonly ?RecoveryClassifier $recovery;
    private ?string $recoveryCursor = null;
    /** @var array<string, array{attempts: int, dueUs: int}> */
    private array $recoveryRetries = [];

    public function __construct(
        private readonly PreparedConfiguration $configuration,
        private readonly ConfigurationSourceInterface $source,
        private readonly PlannerOwnerInterface $owner,
        private readonly ClockInterface $wallClock,
        private readonly MonotonicClockInterface $monotonicClock,
        private readonly PollWaiterInterface $waiter,
        private readonly ExecutionWorkerLauncherInterface $launcher,
        private readonly ParentLifecycleSupervisor $supervisor,
        string $stateDirectory,
        ExecutionLedgerCommandInterface $ledger,
        ExecutionLedgerQueryInterface $query,
        ExecutionOccurrenceCheckpointInterface $checkpoint,
        ?RecoveryProcessObserverInterface $recoveryObserver = null,
        private readonly string $dispatcherId = 'planner',
    ) {
        $this->healthStartedAt = $this->wallClock->now()->getTimestamp();
        // The prepared configuration is the runtime's immutable version fence.
        // Never use the mutable source to rewrite this value: probes must be able to
        // detect a replacement of the source file.
        $sourceId = $configuration->sourceId;
        $sourceRevision = $configuration->sourceRevision;
        if ($sourceId === null || $sourceRevision === null) {
            throw new InvalidArgumentException('Planner runtime requires a source-pinned configuration.');
        }
        $this->pinnedSourceId = $sourceId;
        $this->pinnedRevision = $sourceRevision;
        $this->healthSnapshot = new PlannerHealthSnapshot(
            $this->pinnedSourceId,
            $this->pinnedRevision,
            $owner->ownerToken(),
            (int) getmypid(),
            $this->healthStartedAt,
            $this->healthStartedAt,
        );
        $this->healthSnapshot->write($stateDirectory);
        $this->ledger = $ledger;
        $this->query = $query;
        $this->checkpoint = $checkpoint;
        $this->recovery = new RecoveryClassifier(
            $this->query,
            $recoveryObserver ?? new LinuxProcessObserver(),
            new CurrentRecoveryAdmissionValidator($configuration, $this->pinnedRevision),
        );
        $runId = 'planner-' . substr(hash('sha256', $this->pinnedSourceId . "\0" . $this->pinnedRevision), 0, 32);
        $bindings = new PreparedConfigurationExecutionBindingSource($source, $configuration, $runId);
        foreach ($configuration->tasks as $taskKey => $task) {
            $trigger = $task->trigger;
            $definition = $trigger->definition;
            $initialAnchorCursor = null;
            if ($trigger->reference === 'cron' && isset($definition['from']) && is_string($definition['from'])) {
                // Keep the deliberate first-run anchor reconstructible from the
                // durable cursor. No separate "fresh" flag may be lost on crash.
                $anchor = (new DateTimeImmutable($definition['from']))->modify('-1 second');
                $initialAnchorCursor = 't' . str_pad((string) $anchor->getTimestamp(), 10, '0', STR_PAD_LEFT);
            }
            $cursor = $this->checkpoint->read($taskKey);
            if ($cursor === null) {
                $cursor = $initialAnchorCursor ?? 't' . str_pad((string) $this->wallClock->now()->getTimestamp(), 10, '0', STR_PAD_LEFT);
                $this->checkpoint->initialize($taskKey, $cursor);
            }
            // Equality with the persisted initialization cursor is the durable
            // first-run condition. Once admitted or skipped, the CAS moves it.
            if ($cursor !== $initialAnchorCursor) {
                $initialAnchorCursor = null;
            }
            $this->sources[$taskKey] = new ExecutionOccurrenceSource(
                $taskKey,
                TriggerFactory::create($trigger),
                $bindings,
                $wallClock,
                $this->checkpoint,
                $this->query,
                $task->missedOccurrences,
                $initialAnchorCursor,
                $cursor,
            );
        }
        foreach ($this->sources as $taskKey => $taskSource) {
            $this->coordinators[$taskKey] = new ExecutionIntegrationCoordinator($this->ledger, $taskSource, $launcher, $this->query, $this->pinnedSourceId);
        }
    }

    public function tick(): PlannerResult
    {
        try {
            return $this->tickInternal();
        } catch (Throwable $e) {
            $this->markFailed($e instanceof RuntimeException && $e->getMessage() === 'Planner source version changed'
                ? 'planner.version_mismatch'
                : 'planner.runtime_failed');
            throw $e;
        }
    }

    public function poll(): void
    {
        // A poll can publish new closure facts for the record just scanned;
        // revisit the pinned recovery stream before advancing past it.
        $this->recoveryCursor = null;
        $this->forwardObservations($this->supervisor->poll());
        $this->forwardRecoveryObservations($this->supervisor->takeRecoveryObservations());
    }

    public function shutdown(float $graceSeconds = 5.0): void
    {
        $this->admissionOpen = false;
        try {
            // Publish the lifecycle transition while ownership still proves the
            // snapshot belongs to this planner.
            $this->writeHealth('stopping');
            $this->supervisor->shutdown($graceSeconds);
            // shutdown() retains drained observations separately.
            $this->forwardObservations($this->supervisor->takeShutdownObservations());
            $this->forwardRecoveryObservations($this->supervisor->takeShutdownRecoveryObservations());
        } catch (Throwable $e) {
            $this->markFailed('planner.runtime_failed');
            throw $e;
        } finally {
            $this->owner->release();
        }
    }

    private function tickInternal(): PlannerResult
    {
        if (!$this->admissionOpen) {
            return PlannerResult::stopped();
        }
        // Recovery is pinned to durable execution facts and must run even when
        // the mutable source has been replaced.  A mismatch only closes the
        // admission gate for new occurrences below.
        $this->handleRecovery();
        $sourceValid = false;
        try {
            $sourceValid = $this->source->revision() === $this->pinnedRevision;
        } catch (Throwable) {
            // An unavailable mutable source is equivalent to a revision
            // mismatch for admission, never a reason to skip recovery.
        }
        if (!$sourceValid) {
            // Drain retained recovery closures before reporting the admission
            // fence failure; they are still bound to durable pinned facts.
            $this->poll();
            throw new RuntimeException('Planner source version changed');
        }
        $admitted = 0;
        foreach ($this->sources as $taskKey => $source) {
            $candidate = $source->next();
            if ($candidate === null) {
                continue;
            }
            $policy = new AdmissionPolicy($this->query, $this->configuration->asyncWorkerCapacity, $this->pinnedSourceId);
            $task = $this->configuration->task($taskKey);
            $decision = $policy->decide($taskKey, $task->overlap);
            if ($decision->kind === AdmissionDecision::DEFER_CAPACITY) {
                continue;
            }
            if ($decision->kind === AdmissionDecision::SKIP_OVERLAP) {
                $source->skip($candidate, OccurrenceSkipReason::OVERLAP);
                continue;
            }
            $command = $task->command;
            $timeout = $this->configuration->timeoutFor($task);
            $material = (new ExecutionAdmissionMaterialFactory($this->query, $this->wallClock, $this->dispatcherId, 'ledger', $this->owner->stateDirectory(), static fn(int $generation): string => 'fence-' . $generation . '-' . bin2hex(random_bytes(12))))
                ->forCandidate($candidate, $timeout);
            if ($task->mode === ExecutionMode::SYNC) {
                $result = $this->coordinators[$taskKey]->dispatchSynchronous(
                    $material->record,
                    $material->capability,
                    function () use ($command, $task): ExecutionOutcome {
                        if ($this->source->revision() !== $this->pinnedRevision) {
                            throw new RuntimeException('Planner source version changed');
                        }
                        $map = $this->configuration->commandExecutorMap;
                        if ($map === null) {
                            throw new InvalidArgumentException('Synchronous task execution requires a command executor map.');
                        }
                        return $map->executorFor($command)->execute(new ExecutionContext($command, null, null, $task->metadata));
                    },
                    max(0, $this->wallClock->now()->getTimestamp()),
                );
                if ($result->hasWork()) {
                    ++$admitted;
                }
            } else {
                if ($material->capability === null) {
                    throw new InvalidArgumentException('Asynchronous task admission requires a worker capability.');
                }
                $result = $this->coordinators[$taskKey]->dispatchNext(
                    $material->record,
                    $material->capability,
                    $material->reservation,
                    $timeout === null ? null : new WorkerLifecyclePolicy($timeout->softSeconds, $timeout->hardSeconds),
                );
                if ($result->launchHandle !== null) {
                    $this->launches[$result->launchHandle->value] = ['candidate' => $candidate, 'capability' => $material->capability];
                    ++$admitted;
                }
            }
        }
        $this->poll();
        // A completed control-loop pass is observable progress even when no
        // occurrence was eligible. This is deliberately the only update path.
        $this->writeHealth('running');
        return $admitted > 0 ? PlannerResult::progressed($admitted) : PlannerResult::waiting();
    }

    private static function wallMicroseconds(DateTimeImmutable $now): int
    {
        $seconds = $now->format('U');
        $micros = $now->format('u');
        if (!ctype_digit($seconds) || !ctype_digit($micros)) {
            throw new InvalidArgumentException('Wall clock value is invalid.');
        }
        $secondsInt = (int) $seconds;
        if ($secondsInt < 0 || $secondsInt > intdiv(PHP_INT_MAX, 1000000)) {
            throw new InvalidArgumentException('Wall clock value overflows integer microseconds.');
        }
        $microsInt = (int) $micros;
        // The multiplication can be valid while the remainder still exceeds
        // the available tail of the integer range.
        if ($secondsInt === intdiv(PHP_INT_MAX, 1000000)
            && $microsInt > PHP_INT_MAX % 1000000) {
            throw new InvalidArgumentException('Wall clock value overflows integer microseconds.');
        }
        return $secondsInt * 1000000 + $microsInt;
    }

    private function handleRecovery(): void
    {
        // The constructor establishes this classifier before the runtime is
        // published; keep that lifecycle invariant explicit for the type system.
        $recovery = $this->recovery;
        if ($recovery === null) {
            throw new LogicException('Recovery classifier is not initialized.');
        }
        $nowUs = self::wallMicroseconds($this->wallClock->now());
        $cursor = $this->recoveryCursor;
        try {
            if ($cursor === null) {
                $page = $this->query->recoveryPage($this->pinnedSourceId, null, self::RECOVERY_PAGE_LIMIT);
            } else {
                /** @var non-empty-string $cursor */
                $page = $this->query->recoveryPage($this->pinnedSourceId, $cursor, self::RECOVERY_PAGE_LIMIT);
            }
        } catch (InvalidArgumentException $e) {
            // Index publication legitimately invalidates a hash-bound cursor.
            // Restart the fair scan from the beginning; malformed indexes still
            // remain blocked rather than being hidden by a reset.
            if ($e->getMessage() === 'Recovery cursor is stale.') {
                $this->recoveryCursor = null;
                return;
            }
            // Malformed or missing indexes are an explicit recovery failure;
            // never turn strict index rejection into an apparently healthy tick.
            throw $e;
        } catch (Throwable) {
            // A query failure is transient; retaining the cursor makes the next
            // tick retry the same bounded page without affecting admission.
            return;
        }
        $work = 0;
        $complete = true;
        foreach ($page->records as $record) {
            if ($work >= self::RECOVERY_WORK_BUDGET) {
                $complete = false;
                break;
            }
            ++$work; // record observation/classification
            $retryKey = $this->recoveryRetryKey($record, RecoveryObservation::ROOT);
            if (($this->recoveryRetries[$retryKey]['dueUs'] ?? 0) > $nowUs) {
                continue;
            }
            try {
                // One record per call keeps lookup and handoff failures scoped to
                // that record and never lets one bad entry abort scheduling.
                $actions = $recovery->classifyRecords([$record], $this->pinnedSourceId, $nowUs);
                foreach ($actions as $action) {
                    $scopeCost = count($action->observations);
                    if ($work + 1 + $scopeCost > self::RECOVERY_WORK_BUDGET) {
                        $complete = false;
                        break 2;
                    }
                    ++$work; // action handoff
                    $work += $scopeCost; // role-scoped observation handoffs
                    if ($this->processRecoveryAction($action, $nowUs)) {
                        $this->clearRecoveryRetries($record);
                    } else {
                        // BLOCKED and uncertain actions are transient.  Do not
                        // turn a classification into a one-shot decision.
                        $this->scheduleRecoveryRetry($record, RecoveryObservation::ROOT, $nowUs);
                    }
                }
            } catch (Throwable) {
                $this->scheduleRecoveryRetry($record, RecoveryObservation::ROOT, $nowUs);
            }
        }
        // Do not move past a partially handled page. This is also safe for a
        // provider which accidentally returns more records than requested.
        if ($complete && count($page->records) <= self::RECOVERY_PAGE_LIMIT) {
            $this->recoveryCursor = $page->nextCursor;
        }
    }

    private function processRecoveryAction(RecoveryAction $action, int $nowUs): bool
    {
        if ($action->kind === RecoveryAction::TERMINAL) {
            // Terminal outcome ownership is authoritative, but verified live
            // scopes still belong to this planner until their closure is
            // observed. Closed scope proofs must also be persisted so terminal
            // ownership can eventually leave the active/ownership indexes.
            if (!$this->persistClosedRecoveryObservations($action)) {
                return false;
            }
            $this->attachRecoveryObservations($action);
            return true;
        }
        if ($action->kind === RecoveryAction::AGGREGATE_REDELIVERY) {
            $record = $action->record;
            // Aggregate redelivery is deliberately narrower than same-generation
            // repair: every owned scope must have a durable CLOSED proof.
            if (($record->taskSnapshot['task']['mode'] ?? null) !== ExecutionMode::ASYNC->value
                || $record->isTerminal()
                || $action->recoveryFence === null
                || $action->deadlineUs === null
                || $nowUs >= $action->deadlineUs) {
                return false;
            }
            $roles = [];
            foreach ($action->observations as $observation) {
                if (!$observation->lookup->proof?->isClosed() || $observation->lookup->status !== ProcessLookupResult::CLOSED) {
                    return false;
                }
                $roles[$observation->role] = true;
            }
            if (!isset($roles[RecoveryObservation::ROOT])) {
                return false;
            }
            // A reservation deadline is expressed in seconds while the
            // recovery boundary is exact microseconds. The reservation is
            // exclusive, so preserve an exact-second recovery deadline: a
            // lease [now, deadline) still cannot launch at that boundary.
            $deadlineSecond = intdiv($action->deadlineUs, 1000000);
            $nowSecond = intdiv($nowUs, 1000000);
            $reservationDeadline = $deadlineSecond;
            if ($reservationDeadline <= $nowSecond) {
                return false;
            }

            // CLOSED is a process-scope fact, not merely a classifier result.
            // The aggregate transition must merge those proofs, revoke the old
            // generation, and allocate the replacement while one occurrence lock
            // is held. Backends without this stronger port fail closed rather than
            // falling back to a racy revoke/rotate sequence.
            if (!$this->ledger instanceof ExecutionAggregateRedeliveryInterface) {
                return false;
            }
            $rootClosure = null;
            $commandClosure = null;
            foreach ($action->observations as $observation) {
                $closure = $this->closedRecoveryObservation($action, $observation);
                if ($closure === null) {
                    return false;
                }
                if ($observation->role === RecoveryObservation::ROOT) {
                    if ($rootClosure !== null) {
                        return false;
                    }
                    $rootClosure = $closure;
                } elseif ($observation->role === RecoveryObservation::COMMAND) {
                    if ($commandClosure !== null) {
                        return false;
                    }
                    $commandClosure = $closure;
                } else {
                    return false;
                }
            }
            if ($rootClosure === null) {
                return false;
            }
            $newFence = new ExecutionFence(
                $record->fence->generation + 1,
                'fence-' . ($record->fence->generation + 1) . '-' . bin2hex(random_bytes(12)),
            );
            $rotated = $this->ledger->redeliverAfterClosureUnderLock(
                $record->occurrence,
                $action->recoveryFence,
                $newFence,
                $nowUs,
                $action->expectedFence,
                $this->pinnedSourceId,
                $rootClosure,
                $commandClosure,
            );
            if ($rotated->status !== ExecutionGenerationResult::ALLOCATED || $rotated->record === null) {
                return false;
            }
            $rotatedRecord = $rotated->record;
            $reservation = new ExecutionDispatchReservation(
                $this->dispatcherId,
                $nowSecond,
                $reservationDeadline,
                substr(hash('sha256', 'aggregate-permission:' . (string) $rotatedRecord->occurrence . ':' . $rotatedRecord->fence->token), 0, 32),
            );
            $descriptor = new ExecutionLaunchDescriptor(
                $rotatedRecord->correlation->executionId,
                $rotatedRecord->fence->generation,
                substr(hash('sha256', 'aggregate-descriptor:' . (string) $rotatedRecord->occurrence . ':' . $rotatedRecord->fence->token), 0, 32),
                occurrence: $rotatedRecord->occurrence,
            );
            $capability = $this->capabilityFor($rotatedRecord, $descriptor, $reservation);
            $taskKey = $capability->occurrence->taskKey;
            $result = $this->coordinators[$taskKey]->dispatchRecoveryGeneration(
                $rotatedRecord,
                $capability,
                $reservation,
                $nowUs,
                $this->lifecyclePolicyFor($rotatedRecord),
            );
            if ($result->launchHandle !== null) {
                $this->launches[$result->launchHandle->value] = ['candidate' => (object) [], 'capability' => $capability];
                return true;
            }
            return false;
        }
        if ($action->kind === RecoveryAction::EXPIRED || $action->kind === RecoveryAction::EXPIRED_UNRESOLVED) {
            if ($action->recoveryFence === null) {
                return false;
            }
            // Expiry only closes the relaunch window. It must not discard a
            // closure proof obtained at the same reconsideration: terminal
            // authority remains with the ledger, while these role-scoped facts
            // independently release root/command ownership. In particular, do
            // not route this through occurrence admission or missed policy.
            if (!$this->persistClosedRecoveryObservations($action)) {
                return false;
            }
            $expired = $this->ledger->markRecoveryExpired($action->record->occurrence, $action->expectedFence, $action->recoveryFence, $nowUs, $this->pinnedSourceId);
            if ($expired->status === ExecutionRecoveryResult::RECOVERY_EXPIRED) {
                if ($action->kind === RecoveryAction::EXPIRED) {
                    $this->attachRecoveryObservations($action);
                }
                return true;
            }
            return false;
        }
        // A live worker is classified as WAIT: retaining its verified handle
        // is observation, not a ledger recovery or redelivery.
        $this->attachRecoveryObservations($action);
        if ($action->kind === RecoveryAction::WAIT
            && ($action->lookup?->matched() === true || $this->hasLiveRecoveryObservation($action))) {
            return true;
        }
        if ($action->kind !== RecoveryAction::RELAUNCH || $action->recoveryFence === null) {
            return false;
        }
        $record = $action->record;
        $reservation = $record->dispatchReservation;
        $descriptor = $record->launchDescriptor;
        // A crash can occur before either handoff fact is published. Rebuild
        // each missing fact independently from durable correlation and policy;
        // recovery must never consult the mutable source or current config.
        $deadlineUs = $action->deadlineUs ?? $record->recoveryDeadline;
        $nowSecond = intdiv($nowUs, 1000000);
        $deadlineSecond = $deadlineUs === null ? PHP_INT_MAX : intdiv($deadlineUs, 1000000);
        if ($deadlineSecond <= $nowSecond && ($reservation === null || $reservation->deadline <= $nowSecond)) {
            return false;
        }
        if ($reservation === null) {
            $reservation = new ExecutionDispatchReservation(
                $this->dispatcherId,
                $nowSecond,
                $deadlineSecond > $nowSecond ? $deadlineSecond : PHP_INT_MAX,
                substr(hash('sha256', (string) $record->occurrence . ':' . $record->fence->generation . ':' . $record->correlation->executionId), 0, 32),
            );
        }
        if ($descriptor === null) {
            $descriptor = new ExecutionLaunchDescriptor(
                $record->correlation->executionId,
                $record->fence->generation,
                substr(hash('sha256', 'recovery-descriptor:' . (string) $record->occurrence . ':' . $record->fence->token), 0, 32),
                occurrence: $record->occurrence,
            );
        }
        // A consumed permission with no receipt is not evidence that a worker
        // started. Only a completed absence proof may repair it.
        $lookup = $action->lookup;
        $descriptorFreePreStart = $record->launchDescriptor === null
            && $record->launchObservation === null
            && $record->processIdentity === null
            && $record->startAnchorIdentity === null
            && $record->startObservation === null
            && $record->commandScope === null
            && in_array($record->state, [ExecutionLedgerState::LAUNCH_REQUIRED, ExecutionLedgerState::LAUNCH_REQUESTED], true)
            && ($record->dispatchReservation === null || $record->dispatchReservation->launchPhase === ExecutionDispatchReservation::PREPARED);
        if ($lookup === null && !$descriptorFreePreStart) {
            return false;
        }
        $absenceProven = $lookup !== null && ($lookup->completedNoMatch()
            || ($lookup->status === ProcessLookupResult::CLOSED
                && $lookup->proof?->isClosed() === true)
            || ($record->launchObservation?->closure?->isConfirmed() === true
                && $record->startAnchorIdentity === null
                && $record->startObservation === null
                && $record->launchObservation?->startObservation === null));
        $recovered = null;
        if ($lookup !== null && $action->record->startObservation === null
            && $action->record->launchObservation?->startObservation === null
            && in_array($reservation->launchPhase, ['starting', 'ambiguous'], true)
            && $absenceProven) {
            $repaired = $this->ledger->repairBeforeStartUnderLock(
                $action->record->occurrence,
                $action->expectedFence,
                $action->recoveryFence,
                $this->pinnedSourceId,
                static fn(): bool => $lookup->completedNoMatch()
                    || ($lookup->status === ProcessLookupResult::CLOSED
                        && $lookup->proof?->isClosed() === true)
                    || ($action->record->launchObservation?->closure?->isConfirmed() === true
                        && $action->record->startAnchorIdentity === null
                        && $action->record->startObservation === null
                        && $action->record->launchObservation?->startObservation === null),
                $nowUs,
            );
            if ($repaired->status === ExecutionDispatchResult::SAME_GENERATION_UNCLAIMED
                && $repaired->record !== null) {
                $recovered = ExecutionRecoveryResult::sameGenerationUnclaimed($repaired->record);
            }
        } else {
            $recovered = $this->ledger->recover($action->record->occurrence, $action->recoveryFence, $nowUs, $action->expectedFence, $this->pinnedSourceId);
        }
        if ($recovered === null || $recovered->status !== ExecutionRecoveryResult::SAME_GENERATION_UNCLAIMED || $recovered->record === null) {
            return false;
        }
        $record = $recovered->record;
        // Redispatch must never reuse the consumed/current durable permission.
        // Keep the durable dispatcher and the shortest known deadline, while
        // ensuring this attempt still has a whole-second launch window.
        $durableReservation = $record->dispatchReservation ?? $reservation;
        // An expired dispatch lease may be replaced inside the still-open
        // recovery window; an unexpired lease remains the tighter bound.
        $baseDeadline = $durableReservation->deadline > $nowSecond
            ? $durableReservation->deadline
            : $deadlineSecond;
        $replacementDeadline = min($deadlineSecond, $baseDeadline);
        if ($replacementDeadline <= $nowSecond) {
            return false;
        }
        do {
            $permissionId = bin2hex(random_bytes(16));
        } while ($permissionId === $durableReservation->permissionId);
        $reservation = new ExecutionDispatchReservation(
            $durableReservation->dispatcherId,
            $nowSecond,
            $replacementDeadline,
            $permissionId,
        );
        $capability = $this->capabilityFor($record, $descriptor, $reservation);
        $taskKey = $capability->occurrence->taskKey;
        $result = $this->coordinators[$taskKey]->redispatchSameGeneration(
            $recovered,
            $capability,
            $reservation,
            $nowUs,
            $this->lifecyclePolicyFor($record),
        );
        if ($result->launchHandle !== null) {
            $this->launches[$result->launchHandle->value] = ['candidate' => (object) [], 'capability' => $capability];
            return true;
        }
        return false;
    }

    private function hasLiveRecoveryObservation(RecoveryAction $action): bool
    {
        foreach ($action->observations as $observation) {
            if ($observation->isLive() && $observation->verifiedHandle !== null) {
                return true;
            }
        }
        return false;
    }

    private function recoveryRetryKey(ExecutionLedgerRecord $record, string $role): string
    {
        return (string) $record->occurrence . ':' . $record->fence->generation . ':' . $role;
    }

    private function scheduleRecoveryRetry(ExecutionLedgerRecord $record, string $role, int $nowUs): void
    {
        $key = $this->recoveryRetryKey($record, $role);
        $attempts = ($this->recoveryRetries[$key]['attempts'] ?? 0) + 1;
        $delay = self::RECOVERY_RETRY_BASE_US;
        for ($i = 1; $i < min(6, $attempts); ++$i) {
            $delay = min(self::RECOVERY_RETRY_CAP_US, $delay * 2);
        }
        $dueUs = $delay > PHP_INT_MAX - $nowUs ? PHP_INT_MAX : $nowUs + $delay;
        $this->recoveryRetries[$key] = ['attempts' => $attempts, 'dueUs' => $dueUs];
    }

    private function clearRecoveryRetries(ExecutionLedgerRecord $record): void
    {
        $prefix = (string) $record->occurrence . ':' . $record->fence->generation . ':';
        foreach (array_keys($this->recoveryRetries) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->recoveryRetries[$key]);
            }
        }
    }

    /**
     * Persist verified closed role scopes while the recovery fence is current.
     * A terminal record still owns its process scopes until these facts land.
     */
    private function persistClosedRecoveryObservations(RecoveryAction $action): bool
    {
        $recoveryFence = $action->recoveryFence;
        if ($recoveryFence === null) {
            return false;
        }
        $record = $action->record;
        foreach ($action->observations as $observation) {
            $closure = $this->closedRecoveryObservation($action, $observation);
            if ($closure === null) {
                if ($observation->lookup->status === ProcessLookupResult::CLOSED
                    || $observation->lookup->proof?->isClosed() === true) {
                    return false;
                }
                continue;
            }
            if ($observation->role === RecoveryObservation::ROOT) {
                $result = $this->ledger->observeLaunch(
                    $record->occurrence,
                    $action->expectedFence,
                    $recoveryFence,
                    $closure,
                    $this->pinnedSourceId,
                );
            } else {
                $scope = $record->commandScope;
                $root = $record->launchDescriptor;
                if ($scope === null || $root === null || $scope->descriptor->rootDescriptor === null
                    || $scope->observation?->startObservation === null) {
                    return false;
                }
                $result = $this->ledger->observeCommandUnderLock(
                    $record->occurrence,
                    $action->expectedFence,
                    $recoveryFence,
                    $this->pinnedSourceId,
                    $root,
                    $scope->descriptor,
                    $closure,
                );
            }
            if ($result->status !== ExecutionDispatchResult::ACCEPTED) {
                return false;
            }
        }
        return true;
    }

    private function closedRecoveryObservation(RecoveryAction $action, RecoveryObservation $observation): ?ExecutionLaunchObservation
    {
        $proof = $observation->lookup->proof;
        if ($observation->lookup->status !== ProcessLookupResult::CLOSED || $proof?->isClosed() !== true
            || $proof->identity === null) {
            return null;
        }
        $identity = $proof->identity;
        $executionIdentity = new ExecutionProcessIdentity(
            $identity->pid,
            $identity->processGroupId,
            $identity->sessionId,
            $identity->startTicks,
            $identity->bootId,
        );
        return new ExecutionLaunchObservation(
            $executionIdentity->pid,
            null,
            'recovery-closure',
            max(0, $this->wallClock->now()->getTimestamp()),
            ExecutionProcessClosure::confirmed(null, $proof->reason),
            null,
            $executionIdentity,
            $observation->role === RecoveryObservation::COMMAND
                ? $action->record->commandScope?->observation?->startObservation
                : null,
        );
    }

    /** Retain every verified live role without consulting current planner state. */
    private function attachRecoveryObservations(RecoveryAction $action): void
    {
        foreach ($action->observations as $observation) {
            if (!$observation->isLive() || $observation->verifiedHandle === null
                || $observation->lookup->proof?->status !== ProcessScopeProof::LIVE
                || $observation->recoveryFence === null || $observation->lookup->identity === null) {
                continue;
            }
            $record = $action->record;
            // Recovery scopes have independent timing anchors.  In particular,
            // the nested command must never inherit the worker attempt's
            // deadline/lifecycle anchor.
            $attemptStart = $observation->role === RecoveryObservation::COMMAND
                ? $record->commandScope?->observation?->startObservation
                : $record->attemptStartObservation;
            // Lifecycle and closure timing belongs to the current attempt.
            // Recovery anchors are retained by the classifier for expiry only;
            // never synthesize current-attempt timing from them.
            $startedAt = $attemptStart === null ? null : $attemptStart->monotonicNanoseconds / 1000000000.0;
            $wallStartedAt = $attemptStart?->wallMicroseconds;
            // Publish only facts already present in the recovery observation;
            // this never creates a start boundary or changes a deadline.
            // A command identity is never evidence about the root process.  Root
            // facts may only be filled by a root observation.  Command facts are
            // merged through the nested, role-scoped operation and only when the
            // persisted command reservation already proves that this scope was
            // observed with complete timing facts.
            if ($observation->role === RecoveryObservation::ROOT && $record->processIdentity === null) {
                $identity = $observation->lookup->identity;
                $executionIdentity = new ExecutionProcessIdentity(
                    $identity->pid,
                    $identity->processGroupId,
                    $identity->sessionId,
                    $identity->startTicks,
                    $identity->bootId,
                );
                $this->ledger->observeLaunch(
                    $observation->occurrence,
                    $observation->executionFence,
                    $observation->recoveryFence,
                    new ExecutionLaunchObservation($identity->pid, null, 'recovery-facts', max(0, $this->wallClock->now()->getTimestamp()), null, null, $executionIdentity, $record->attemptStartObservation),
                    $this->pinnedSourceId,
                );
            } elseif ($observation->role === RecoveryObservation::COMMAND) {
                $scope = $record->commandScope;
                $stored = $scope?->observation;
                if ($scope !== null
                    && $scope->reservation->launchPhase === ExecutionDispatchReservation::OBSERVED
                    && $stored !== null
                    && $stored->processIdentity !== null
                    && $stored->startObservation !== null
                    && $scope->descriptor->rootDescriptor !== null) {
                    $identity = $observation->lookup->identity;
                    $executionIdentity = new ExecutionProcessIdentity(
                        $identity->pid,
                        $identity->processGroupId,
                        $identity->sessionId,
                        $identity->startTicks,
                        $identity->bootId,
                    );
                    $this->ledger->observeCommandUnderLock(
                        $observation->occurrence,
                        $observation->executionFence,
                        $observation->recoveryFence,
                        $this->pinnedSourceId,
                        $scope->descriptor->rootDescriptor,
                        $scope->descriptor,
                        new ExecutionLaunchObservation($identity->pid, null, 'recovery-facts', max(0, $this->wallClock->now()->getTimestamp()), null, null, $executionIdentity, $stored->startObservation),
                    );
                }
            }
            $this->supervisor->retainRecovery(
                $observation,
                $this->lifecyclePolicyFor($record),
                $startedAt,
                $wallStartedAt,
                $attemptStart,
            );
        }
    }

    /** @param list<RecoveryClosureObservation> $observations */
    private function forwardRecoveryObservations(array $observations): void
    {
        foreach ($observations as $observation) {
            $fence = $observation->recoveryFence;
            if ($fence === null || $observation->descriptor->occurrence === null) {
                continue;
            }
            $closure = $observation->closure;
            $observedAt = max(0, $this->wallClock->now()->getTimestamp());
            $closure = new ExecutionLaunchObservation(
                $closure->pid,
                $closure->error,
                $closure->classification,
                $observedAt,
                $closure->closure,
                $observation->retainedHandle,
                $closure->processIdentity,
                $closure->startObservation,
            );
            if ($observation->role === RecoveryObservation::ROOT) {
                $this->ledger->observeLaunch($observation->occurrence, $observation->executionFence, $fence, $closure, $this->pinnedSourceId);
                continue;
            }
            $root = $observation->rootDescriptor;
            if ($root === null || $root->role !== ExecutionLaunchDescriptor::ROLE_WORKER) {
                continue;
            }
            $this->ledger->observeCommandUnderLock($observation->occurrence, $observation->executionFence, $fence, $this->pinnedSourceId, $root, $observation->descriptor, $closure);
        }
    }

    private function capabilityFor(ExecutionLedgerRecord $record, ?ExecutionLaunchDescriptor $fallbackDescriptor = null, ?ExecutionDispatchReservation $fallbackReservation = null): ExecutionWorkerCapability
    {
        $descriptor = $record->launchDescriptor ?? $fallbackDescriptor;
        $reservation = $fallbackReservation ?? $record->dispatchReservation;
        if ($record->recoveryFence === null || $descriptor === null
            || $reservation === null || $record->sourceRevision === null) {
            throw new InvalidArgumentException('Recovery record is missing worker capability facts.');
        }
        /** @var array<string, mixed> $taskSnapshot */
        $taskSnapshot = $record->taskSnapshot;
        return new ExecutionWorkerCapability(
            $record->occurrence,
            $record->correlation->attemptId,
            $record->correlation->runId,
            $record->correlation->executionId,
            $record->fence->generation,
            $record->fence->token,
            $record->configurationFingerprint,
            hash('sha256', PreparedConfiguration::canonical($taskSnapshot)),
            'ledger',
            $this->owner->stateDirectory(),
            $record->sourceRevision,
            $taskSnapshot,
            $record->recoveryFence,
            launchDescriptor: $descriptor,
            permissionId: $reservation->permissionId,
        );
    }

    private function lifecyclePolicyFor(ExecutionLedgerRecord $record): ?WorkerLifecyclePolicy
    {
        $timeout = $record->taskSnapshot['task']['timeout'] ?? null;
        if (!is_array($timeout)) {
            return null;
        }
        $soft = $timeout[0] ?? null;
        $hard = $timeout[1] ?? null;
        if (($soft !== null && !is_int($soft) && !is_float($soft))
            || ($hard !== null && !is_int($hard) && !is_float($hard))) {
            return null;
        }
        if ($soft === null && $hard === null) {
            return null;
        }
        return new WorkerLifecyclePolicy($soft === null ? null : (float) $soft, $hard === null ? null : (float) $hard);
    }

    private function writeHealth(string $phase, ?string $error = null): void
    {
        $this->healthSnapshot = new PlannerHealthSnapshot(
            $this->pinnedSourceId,
            $this->pinnedRevision,
            $this->owner->ownerToken(),
            (int) getmypid(),
            $this->healthStartedAt,
            $this->wallClock->now()->getTimestamp(),
            $phase,
            $error,
        );
        $this->healthSnapshot->write($this->owner->stateDirectory());
    }

    private function markFailed(string $error): void
    {
        try {
            $this->writeHealth('failed', substr($error, 0, 256));
        } catch (Throwable) {
            // Preserve the original runtime failure when the health sink is
            // unavailable as well.
        }
    }

    /** @param list<ExecutionLaunchObservation> $observations */
    private function forwardObservations(array $observations): void
    {
        foreach ($observations as $observation) {
            $handle = $observation->launchHandle;
            if ($handle === null) {
                continue;
            }
            $entry = $this->launches[$handle->value] ?? null;
            if ($entry === null) {
                continue;
            }
            $taskKey = $entry['capability']->occurrence->taskKey;
            // Supervisor timestamps are monotonic; ledger timestamps are wall
            // clock seconds. Translate the clock domain at this boundary.
            $current = $this->query->observe($entry['capability']->occurrence);
            $observedAt = max(
                0,
                $this->wallClock->now()->getTimestamp(),
                $current?->updatedAt ?? 0,
            );
            $observation = new ExecutionLaunchObservation(
                $observation->pid,
                $observation->error,
                $observation->classification,
                $observedAt,
                $observation->closure,
                $handle,
                $observation->processIdentity,
                $observation->startObservation,
            );
            $this->coordinators[$taskKey]->observeLaunch(
                $entry['capability']->occurrence,
                new ExecutionFence($entry['capability']->generation, $entry['capability']->fenceToken),
                $observation,
            );
            // A poll may publish the queued start fact immediately before the
            // confirmed closure for the same launch. Keep correlation alive for
            // the whole batch so both observations reach the coordinator in
            // order; the terminal closure is the point at which ownership ends.
            if ($observation->closure?->isConfirmed() === true) {
                unset($this->launches[$handle->value]);
            }
        }
    }
}
