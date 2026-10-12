<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use Closure;
use InvalidArgumentException;
use LogicException;
use Psr\Clock\ClockInterface;
use Yiisoft\Scheduler\Config\{ConfigurationSourceInterface,PreparedConfiguration,RuntimeConfigurationSourceInterface};
use Yiisoft\Scheduler\Executor\CommandExecutorRuntimeFactory;
use Yiisoft\Scheduler\Integration\Async\{ExecutionOccurrenceCandidate,MonotonicClockInterface,PollWaiterInterface};

use function count;

/**
 * The library planner boundary.  It owns the lease and the source fence; all
 * durable admission and execution remains in the integration contracts.
 */
final class Planner implements PlannerInterface
{
    private bool $stopping = false;
    private ?PreparedConfiguration $preparedConfiguration = null;
    private ?PlannerOwnerInterface $owner = null;
    private float $lastMonotonic = 0.0;
    private readonly FairCandidateSelector $selector;

    /** @var Closure(string): PlannerOwnerInterface */
    private readonly Closure $acquireOwner;
    /** @var null|Closure(ConfigurationSourceInterface): PreparedConfiguration */
    private readonly ?Closure $prepare;
    /** @var null|Closure(PreparedConfiguration): iterable<ExecutionOccurrenceCandidate> */
    private readonly ?Closure $candidates;
    /** @var null|Closure(ExecutionOccurrenceCandidate): bool */
    private readonly ?Closure $admit;

    /**
     * @param callable(string): PlannerOwnerInterface $acquireOwner
     * @param null|callable(ConfigurationSourceInterface): PreparedConfiguration $prepare
     * @param null|callable(PreparedConfiguration): iterable<ExecutionOccurrenceCandidate> $candidates
     * @param null|callable(ExecutionOccurrenceCandidate): bool $admit
     */
    public function __construct(
        private readonly ConfigurationSourceInterface $source,
        callable $acquireOwner,
        ?callable $prepare = null,
        ?callable $candidates = null,
        ?callable $admit = null,
        private readonly ?ClockInterface $wallClock = null,
        private readonly ?MonotonicClockInterface $monotonicClock = null,
        private readonly ?PollWaiterInterface $waiter = null,
        private readonly PlannerOptions $options = new PlannerOptions(),
        private readonly ?CommandExecutorRuntimeFactory $runtimeFactory = null,
    ) {
        $this->acquireOwner = Closure::fromCallable($acquireOwner);
        $this->prepare = $prepare === null ? null : Closure::fromCallable($prepare);
        $this->candidates = $candidates === null ? null : Closure::fromCallable($candidates);
        $this->admit = $admit === null ? null : Closure::fromCallable($admit);
        $this->selector = new FairCandidateSelector();
    }

    public function tick(): PlannerResult
    {
        if ($this->stopping) {
            return PlannerResult::stopped();
        }
        try {
            $this->ensureStarted();
        } catch (InvalidArgumentException) {
            $this->stop();
            return PlannerResult::versionMismatch();
        }
        if ($this->revisionChanged()) {
            $this->stop();
            return PlannerResult::versionMismatch();
        }

        $configuration = $this->preparedConfiguration;
        if ($configuration === null) {
            throw new LogicException('Planner prepared configuration is unavailable after startup.');
        }
        $items = [];
        if ($this->candidates !== null) {
            foreach (($this->candidates)($configuration) as $candidate) {
                if (!$candidate instanceof ExecutionOccurrenceCandidate) {
                    throw new InvalidArgumentException('Planner candidates must be occurrence candidates.');
                }
                $items[] = $candidate;
                // A provider must expose a bounded ready window. This guard is
                // also protection against accidentally passing an infinite scan.
                if (count($items) >= $this->options->candidateScanLimit) {
                    break;
                }
            }
        }

        /** @var list<ExecutionOccurrenceCandidate> $selected */
        $selected = $this->selector->select($items, $this->options->admissionLimit);
        $admitted = 0;
        foreach ($selected as $candidate) {
            // This fence deliberately sits inside the admission loop: a source
            // changing between two admissions must fail closed.
            if ($this->revisionChanged()) {
                $this->stop();
                return PlannerResult::versionMismatch();
            }
            if ($this->admit !== null && ($this->admit)($candidate) === true) {
                ++$admitted;
            }
        }
        if ($admitted > 0) {
            return PlannerResult::progressed($admitted);
        }
        $this->wait();
        return PlannerResult::waiting();
    }

    public function run(): PlannerResult
    {
        $result = PlannerResult::stopped();
        try {
            while (!$this->stopping) {
                $result = $this->tick();
            }
        } finally {
            $this->stop();
        }
        return $result;
    }

    public function stop(): void
    {
        $this->stopping = true;
        if ($this->owner !== null) {
            $owner = $this->owner;
            $this->owner = null;
            $owner->release();
        }
    }

    public function preparedConfiguration(): ?PreparedConfiguration
    {
        return $this->preparedConfiguration;
    }

    public function owner(): ?PlannerOwnerInterface
    {
        return $this->owner;
    }

    private function ensureStarted(): void
    {
        if ($this->owner !== null) {
            return;
        }
        // sourceId is the only source fact read before ownership is acquired.
        $sourceId = $this->source->sourceId();
        $this->owner = ($this->acquireOwner)($sourceId);
        if ($this->owner->sourceId() !== $sourceId) {
            $this->stop();
            throw new InvalidArgumentException('Planner owner source identity is not pinned.');
        }
        $this->preparedConfiguration = $this->prepare === null
            ? $this->runtimePreparation()
            : ($this->prepare)($this->source);
        if ($this->preparedConfiguration->sourceId !== $sourceId
            || $this->preparedConfiguration->sourceRevision !== $this->source->revision()) {
            $this->stop();
            throw new InvalidArgumentException('Planner configuration source is not pinned.');
        }
        $this->lastMonotonic = $this->monotonicClock?->now() ?? 0.0;
    }

    private function runtimePreparation(): PreparedConfiguration
    {
        if (!$this->source instanceof RuntimeConfigurationSourceInterface) {
            throw new InvalidArgumentException('Planner source does not provide prepared configuration.');
        }
        if ($this->runtimeFactory === null) {
            throw new InvalidArgumentException('Planner runtime preparation requires a command executor runtime factory.');
        }
        return $this->source->prepare($this->runtimeFactory);
    }

    private function revisionChanged(): bool
    {
        return $this->preparedConfiguration === null
            || $this->source->sourceId() !== $this->preparedConfiguration->sourceId
            || $this->source->revision() !== $this->preparedConfiguration->sourceRevision;
    }

    private function wait(): void
    {
        if ($this->waiter === null) {
            return;
        }
        $now = $this->monotonicClock?->now();
        $wait = min($this->options->idleWaitSeconds, $this->options->maximumWaitSeconds);
        if ($now !== null) {
            $wait = min($wait, max($this->options->minimumWaitSeconds, $this->options->idleWaitSeconds - max(0.0, $now - $this->lastMonotonic)));
            $this->lastMonotonic = $now;
        }
        $this->waiter->wait(max($this->options->minimumWaitSeconds, $wait));
    }
}
