<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use Throwable;
use InvalidArgumentException;
use RuntimeException;
use Yiisoft\Scheduler\Integration\Async\{ExecutionLaunchObservation, ExecutionProcessClosure, ExecutionWorkerLaunchHandle, ExecutionWorkerLaunchRequest, MonotonicClockInterface, PollWaiterInterface};
use Yiisoft\Scheduler\Integration\Async\Process\{ExecutionWorkerProcessHandleObserverInterface, ProcessHandleInterface, ProcessResult};

use function is_finite;
use function preg_replace;
use function substr;

/**
 * Small parent-side ownership boundary for launched workers.
 *
 * This class intentionally does not infer a business outcome from an exit. It
 * only reports process facts; the ledger remains the authority for outcomes.
 */
final class ParentLifecycleSupervisor implements ExecutionWorkerProcessHandleObserverInterface
{
    /** @var array<string, RetainedWorker> */
    private array $workers = [];
    private int $nextId = 1;
    private bool $accepting = true;
    private bool $shutdownStarted = false;
    /** @var list<ExecutionLaunchObservation> */
    private array $shutdownObservations = [];

    public function __construct(
        private readonly MonotonicClockInterface $clock,
        private readonly PollWaiterInterface $waiter,
    ) {}

    public function retain(ExecutionWorkerLaunchRequest $request, ProcessHandleInterface $handle): ExecutionWorkerLaunchHandle
    {
        if (!$this->accepting) {
            throw new RuntimeException('Worker admission is stopped.');
        }
        $launch = new ExecutionWorkerLaunchHandle('worker-' . $this->nextId++);
        $this->workers[$launch->value] = new RetainedWorker(
            $launch,
            $request,
            $handle,
            $this->validatedLaunchStartedAt($request),
            $request->lifecyclePolicy,
        );
        return $launch;
    }

    public function stopAdmission(): void
    {
        $this->accepting = false;
    }

    public function isAccepting(): bool
    {
        return $this->accepting;
    }

    /** @return list<ExecutionLaunchObservation> */
    public function poll(): array
    {
        $observations = [];
        foreach (array_keys($this->workers) as $key) {
            if (!isset($this->workers[$key])) {
                continue;
            }
            $worker = $this->workers[$key];
            $now = $this->clock->now();
            // Equal deadlines are deliberately handled soft-first.
            if ($worker->lifecyclePolicy !== null) {
                if (!$this->shutdownStarted && !$worker->softSignalled && $worker->lifecyclePolicy->softSeconds !== null && $now >= $worker->launchedAt + $worker->lifecyclePolicy->softSeconds) {
                    try {
                        $worker->process->terminateSoft();
                    } catch (Throwable) { /* ownership is retained */
                    }
                    $worker->softSignalled = true;
                }
                if (!$this->shutdownStarted && !$worker->hardSignalled && $worker->lifecyclePolicy->hardSeconds !== null && $now >= $worker->launchedAt + $worker->lifecyclePolicy->hardSeconds) {
                    try {
                        $worker->process->terminateHard();
                    } catch (Throwable) { /* ownership is retained */
                    }
                    $worker->hardSignalled = true;
                }
            }

            try {
                // poll() is the handle's single terminal observation. It
                // performs the owned-scope check and reaps the Symfony
                // process; a second isQuiescent() call here races with /proc
                // entries disappearing and can strand a completed launch as
                // claimed.
                $result = $worker->process->poll();
            } catch (Throwable) {
                $observations[] = new ExecutionLaunchObservation(
                    null,
                    'poll failed',
                    'poll-failed',
                    max(0, (int) $now),
                    ExecutionProcessClosure::unknown('poll failed'),
                    $worker->launch,
                );
                continue;
            }
            // A null poll retains ownership. A result after the quiescence
            // check is the confirmed terminal observation from the handle.
            if ($result === null) {
                continue;
            }
            $observations[] = $this->confirmedObservation($worker, $result, $now);
            unset($this->workers[$key]);
        }
        return $observations;
    }

    /** Stop admission and drain owned scopes within a bounded grace period. */
    public function shutdown(float $graceSeconds): bool
    {
        if ($graceSeconds < 0 || !is_finite($graceSeconds)) {
            throw new InvalidArgumentException('Shutdown grace must be finite and non-negative.');
        }
        $this->stopAdmission();
        $this->shutdownStarted = true;
        $deadline = $this->clock->now() + $graceSeconds;
        foreach ($this->workers as $worker) {
            if (!$worker->softSignalled) {
                try {
                    $worker->process->terminateSoft();
                } catch (Throwable) { /* unresolved ownership */
                }
                // Mark even a failed signal as sent: shutdown must not duplicate it.
                $worker->softSignalled = true;
            }
        }
        while ($this->workers !== []) {
            $this->shutdownObservations = [...$this->shutdownObservations, ...$this->poll()];
            if ($this->workers === []) {
                return true;
            }
            $now = $this->clock->now();
            if ($now >= $deadline) {
                foreach ($this->workers as $worker) {
                    if (!$worker->hardSignalled) {
                        try {
                            $worker->process->terminateHard();
                        } catch (Throwable) { /* unresolved ownership */
                        }
                        $worker->hardSignalled = true;
                    }
                }
                $this->shutdownObservations = [...$this->shutdownObservations, ...$this->poll()];
                return $this->workers === [];
            }
            $this->waiter->wait(min(0.05, $deadline - $now));
        }
        return true;
    }

    /**
     * Return and clear observations produced by polls performed during shutdown.
     *
     * The launch handle on each observation remains the correlation boundary for
     * forwarding it to the integration coordinator after shutdown.
     *
     * @return list<ExecutionLaunchObservation>
     */
    public function takeShutdownObservations(): array
    {
        $observations = $this->shutdownObservations;
        $this->shutdownObservations = [];
        return $observations;
    }

    public function hasUnresolvedWorkers(): bool
    {
        return $this->workers !== [];
    }

    /** @return array<string, RetainedWorker> */
    public function retained(): array
    {
        return $this->workers;
    }

    private function confirmedObservation(RetainedWorker $worker, ProcessResult $result, float $now): ExecutionLaunchObservation
    {
        return new ExecutionLaunchObservation(
            null,
            $this->resultDiagnostic($result),
            'quiescent',
            max(0, (int) $now),
            ExecutionProcessClosure::confirmed($result->exitCode, 'owned scope quiescent'),
            $worker->launch,
        );
    }

    private function resultDiagnostic(ProcessResult $result): ?string
    {
        if ($result->exitCode === 0 && $result->stderr === '') {
            return null;
        }
        $stderr = preg_replace('/[\\x00-\\x1F\\x7F]+/', ' ', $result->stderr) ?? '';
        $diagnostic = 'worker exit code ' . ($result->exitCode === null ? 'unknown' : (string) $result->exitCode);
        if ($stderr !== '') {
            $diagnostic .= ': ' . $stderr;
        }
        return substr($diagnostic, 0, 4096);
    }

    private function validatedLaunchStartedAt(ExecutionWorkerLaunchRequest $request): float
    {
        if (!is_finite($request->launchStartedAt) || $request->launchStartedAt < 0.0) {
            throw new InvalidArgumentException('Worker launch start time is invalid.');
        }
        return $request->launchStartedAt;
    }
}
