<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Throwable;
use Yiisoft\Scheduler\Config\{ConfigurationSourceInterface,PreparedConfiguration};
use Yiisoft\Scheduler\Trigger\TriggerFactory;
use Yiisoft\Scheduler\Executor\ExecutionContext;
use Yiisoft\Scheduler\ExecutionMode;
use Yiisoft\Scheduler\Integration\Async\{ExecutionIntegrationCoordinator,ExecutionOccurrenceSource,ExecutionOutcome,ExecutionWorkerCapability,ExecutionWorkerLauncherInterface,ExecutionLedgerInterface,ExecutionLedgerQueryInterface,ExecutionOccurrenceCheckpointInterface,MonotonicClockInterface,PollWaiterInterface,WorkerLifecyclePolicy};
use Yiisoft\Scheduler\Integration\Async\ExecutionFence;
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchObservation;
use Yiisoft\Scheduler\Integration\Async\PreparedConfigurationExecutionBindingSource;
use Yiisoft\Scheduler\Integration\Async\OccurrenceSkipReason;
use RuntimeException;

use function is_string;

use const STR_PAD_LEFT;

/** Concrete parent-side composition for one owned, pinned configuration source. */
final class SourcePlannerRuntime
{
    private readonly ExecutionLedgerInterface $ledger;
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
        ExecutionLedgerInterface $ledger,
        ExecutionOccurrenceCheckpointInterface $checkpoint,
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
        $this->checkpoint = $checkpoint;
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
                $this->ledger,
                $task->missedOccurrences,
                $initialAnchorCursor,
                $cursor,
            );
        }
        foreach ($this->sources as $taskKey => $taskSource) {
            $this->coordinators[$taskKey] = new ExecutionIntegrationCoordinator($this->ledger, $taskSource, $launcher);
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
        $this->forwardObservations($this->supervisor->poll());
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
        if ($this->source->revision() !== $this->pinnedRevision) {
            throw new RuntimeException('Planner source version changed');
        }
        $admitted = 0;
        foreach ($this->sources as $taskKey => $source) {
            $candidate = $source->next();
            if ($candidate === null) {
                continue;
            }
            if (!$this->ledger instanceof ExecutionLedgerQueryInterface) {
                // A ledger without the admission query port cannot safely admit
                // work; retain the conservative no-admission result.
                continue;
            }
            $policy = new AdmissionPolicy($this->ledger, $this->configuration->asyncWorkerCapacity);
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
            $material = (new ExecutionAdmissionMaterialFactory($this->ledger, $this->wallClock, $this->dispatcherId, 'ledger', $this->owner->stateDirectory(), static fn(int $generation): string => 'fence-' . $generation . '-' . bin2hex(random_bytes(12))))
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
            $current = $this->ledger->observe($entry['capability']->occurrence);
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
            );
            $this->coordinators[$taskKey]->observeLaunch(
                $entry['capability']->occurrence,
                new ExecutionFence($entry['capability']->generation, $entry['capability']->fenceToken),
                $observation,
            );
            unset($this->launches[$handle->value]);
        }
    }
}
