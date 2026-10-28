<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use Throwable;
use InvalidArgumentException;
use RuntimeException;
use Yiisoft\Scheduler\Integration\Async\{ExecutionLaunchObservation, ExecutionProcessClosure, ExecutionProcessIdentity, ExecutionStartObservation, ExecutionWorkerLaunchHandle, ExecutionWorkerLaunchRequest, MonotonicClockInterface, PollWaiterInterface, WorkerLifecyclePolicy};
use Yiisoft\Scheduler\Integration\Async\Process\{ExecutionWorkerProcessHandleObserverInterface, ProcessHandleInterface, ProcessIdentity, ProcessLaunchResult, ProcessResult, ProcessScopeProof};
use Yiisoft\Scheduler\Integration\Async\Recovery\RecoveryObservation;

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
    /** @var array<string, RetainedRecoveryScope> */
    private array $recoveryScopes = [];
    /** @var list<RecoveryClosureObservation> */
    private array $recoveryObservations = [];
    /** @var list<RecoveryClosureObservation> */
    private array $shutdownRecoveryObservations = [];
    private int $nextId = 1;
    private bool $accepting = true;
    private bool $shutdownStarted = false;
    /** @var list<ExecutionLaunchObservation> */
    private array $startObservations = [];
    /** @var list<ExecutionLaunchObservation> */
    private array $shutdownObservations = [];

    public function __construct(
        private readonly MonotonicClockInterface $clock,
        private readonly PollWaiterInterface $waiter,
        private readonly ?CommandScopeProcessResolverInterface $commandScopeResolver = null,
    ) {}

    public function retain(ExecutionWorkerLaunchRequest $request, ProcessLaunchResult $launch): ExecutionWorkerLaunchHandle
    {
        if (!$this->accepting) {
            throw new RuntimeException('Worker admission is stopped.');
        }
        if ($launch->identity !== null && $launch->identity->descriptor->marker() !== $request->descriptor->marker()) {
            throw new InvalidArgumentException('Launched process descriptor does not match the request.');
        }
        $startedAt = $this->validatedLaunchStartedAt($launch);
        if ($startedAt === null) {
            throw new InvalidArgumentException('Fresh worker launch start time is invalid.');
        }
        return $this->retainLaunch($request, $launch, $startedAt);
    }

    /** Attach only a process identity already verified by the recovery port. */
    public function retainReattached(ExecutionWorkerLaunchRequest $request, ProcessHandleInterface $handle, ProcessIdentity $identity, ?int $wallStartedAtMicros, ?ExecutionStartObservation $attemptStart = null): ExecutionWorkerLaunchHandle
    {
        if (!$this->accepting) {
            throw new RuntimeException('Worker admission is stopped.');
        }
        if ($identity->descriptor->marker() !== $request->descriptor->marker()) {
            throw new InvalidArgumentException('Launched process descriptor does not match the request.');
        }
        // An absent attempt observation is incomplete timing, not a launch
        // boundary. Keep the verified process owned without inventing one.
        // Retain the supplied wall fact independently; the attempt observation
        // supplies only the current-attempt monotonic/start observation.
        $monotonicNanoseconds = $attemptStart?->monotonicNanoseconds;
        $startedAt = $monotonicNanoseconds === null ? null : $monotonicNanoseconds / 1000000000.0;
        $launch = new ProcessLaunchResult(
            $handle,
            $startedAt,
            $wallStartedAtMicros,
            $identity,
        );
        return $this->retainLaunch($request, $launch, $this->validatedLaunchStartedAt($launch), $attemptStart);
    }

    /** Retain a verified live recovery scope independently from a fresh root. */
    public function retainRecovery(RecoveryObservation $observation, ?WorkerLifecyclePolicy $lifecyclePolicy, ?float $startedAt = null, ?int $wallStartedAtMicros = null, ?ExecutionStartObservation $attemptStart = null): ExecutionWorkerLaunchHandle
    {
        if (!$this->accepting) {
            throw new RuntimeException('Worker admission is stopped.');
        }
        if (!$observation->isLive() || $observation->verifiedHandle === null || $observation->lookup->identity === null || $observation->recoveryFence === null
            || $observation->lookup->proof === null || $observation->lookup->proof->status !== ProcessScopeProof::LIVE) {
            throw new InvalidArgumentException('Only a verified, fenced live recovery observation may be retained.');
        }
        $identity = $observation->lookup->identity;
        $this->validateRecoveryTiming($startedAt, $wallStartedAtMicros, $attemptStart);
        // A recovery scan can race the normal launch handoff. The authenticated
        // process identity is the ownership boundary, so retain one scope in
        // one registry only; otherwise both paths poll and signal it.
        $identityKey = $this->processIdentityKey($identity);
        foreach ($this->workers as $worker) {
            if ($worker->processIdentity !== null && $this->processIdentityKey($worker->processIdentity) === $identityKey) {
                return $worker->launch;
            }
        }
        $key = $this->recoveryKey($observation, $identity);
        if (isset($this->recoveryScopes[$key])) {
            return $this->recoveryScopes[$key]->handle;
        }
        $handle = new ExecutionWorkerLaunchHandle('recovery-' . $this->nextId++);
        $this->recoveryScopes[$key] = new RetainedRecoveryScope($handle, $observation, $observation->verifiedHandle, $identity, $lifecyclePolicy, $startedAt, $wallStartedAtMicros, $attemptStart);
        return $handle;
    }

    /** Alias emphasizing that this is a separately owned process scope. */
    public function retainRecoveryScope(RecoveryObservation $observation, ?WorkerLifecyclePolicy $lifecyclePolicy, ?float $startedAt = null, ?int $wallStartedAtMicros = null, ?ExecutionStartObservation $attemptStart = null): ExecutionWorkerLaunchHandle
    {
        return $this->retainRecovery($observation, $lifecyclePolicy, $startedAt, $wallStartedAtMicros, $attemptStart);
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
        $observations = $this->startObservations;
        $this->startObservations = [];
        foreach (array_keys($this->workers) as $key) {
            if (!isset($this->workers[$key])) {
                continue;
            }
            $worker = $this->workers[$key];
            $now = $this->clock->now();
            // Equal deadlines are deliberately handled soft-first.
            if ($worker->lifecyclePolicy !== null) {
                if (!$this->shutdownStarted && $worker->launchedAt !== null && !$worker->softSignalled && $worker->lifecyclePolicy->softSeconds !== null && $now >= $worker->launchedAt + $worker->lifecyclePolicy->softSeconds) {
                    try {
                        $worker->process->terminateSoft();
                    } catch (Throwable) { /* ownership is retained */
                    }
                    $worker->softSignalled = true;
                    $this->signalCommand($worker, false);
                }
                if (!$this->shutdownStarted && $worker->launchedAt !== null && !$worker->hardSignalled && $worker->lifecyclePolicy->hardSeconds !== null && $now >= $worker->launchedAt + $worker->lifecyclePolicy->hardSeconds) {
                    try {
                        $worker->process->terminateHard();
                    } catch (Throwable) { /* ownership is retained */
                    }
                    $worker->hardSignalled = true;
                    $this->signalCommand($worker, true);
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
            // A handle owns the proof boundary. Never infer terminal ownership
            // from a result-shaped value unless the handle itself supplied it;
            // poll() is the only operation allowed to release the retained scope.
            $proof = $worker->process->scopeProof();
            if (!$proof->isClosed()) {
                continue;
            }
            $observations[] = $this->confirmedObservation($worker, $result, $now);
            unset($this->workers[$key]);
        }
        /** @var list<RecoveryClosureObservation> $recoveryObservations */
        $recoveryObservations = $this->pollRecoveryScopes();
        $this->recoveryObservations = [...$this->recoveryObservations, ...$recoveryObservations];
        return $observations;
    }

    /** @return list<RecoveryClosureObservation> */
    public function pollRecovery(): array
    {
        return $this->pollRecoveryScopes();
    }

    /** @return list<RecoveryClosureObservation> */
    public function takeRecoveryObservations(): array
    {
        $observations = $this->recoveryObservations;
        $this->recoveryObservations = [];
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
                $this->signalCommand($worker, false);
            }
        }
        foreach ($this->recoveryScopes as $scope) {
            if (!$scope->softSignalled) {
                try {
                    $scope->process->terminateSoft();
                } catch (Throwable) {
                }
                $scope->softSignalled = true;
            }
        }
        while ($this->workers !== [] || $this->recoveryScopes !== []) {
            /** @var list<ExecutionLaunchObservation> $shutdownObservations */
            $shutdownObservations = $this->poll();
            $this->shutdownObservations = [...$this->shutdownObservations, ...$shutdownObservations];
            /** @var list<RecoveryClosureObservation> $shutdownRecoveryObservations */
            $shutdownRecoveryObservations = $this->takeRecoveryObservations();
            $this->shutdownRecoveryObservations = [...$this->shutdownRecoveryObservations, ...$shutdownRecoveryObservations];
            if ($this->workers === [] && $this->recoveryScopes === []) {
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
                        $this->signalCommand($worker, true);
                    }
                }
                foreach ($this->recoveryScopes as $scope) {
                    if (!$scope->hardSignalled) {
                        try {
                            $scope->process->terminateHard();
                        } catch (Throwable) {
                        }
                        $scope->hardSignalled = true;
                    }
                }
                /** @var list<ExecutionLaunchObservation> $shutdownObservations */
                $shutdownObservations = $this->poll();
                $this->shutdownObservations = [...$this->shutdownObservations, ...$shutdownObservations];
                /** @var list<RecoveryClosureObservation> $shutdownRecoveryObservations */
                $shutdownRecoveryObservations = $this->takeRecoveryObservations();
                $this->shutdownRecoveryObservations = [...$this->shutdownRecoveryObservations, ...$shutdownRecoveryObservations];
                return $this->workers === [] && $this->recoveryScopes === [];
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

    /** @return list<RecoveryClosureObservation> */
    public function takeShutdownRecoveryObservations(): array
    {
        $observations = $this->shutdownRecoveryObservations;
        $this->shutdownRecoveryObservations = [];
        return $observations;
    }

    public function hasUnresolvedWorkers(): bool
    {
        return $this->workers !== [] || $this->recoveryScopes !== [];
    }

    /** @return array<string, RetainedWorker> */
    public function retained(): array
    {
        return $this->workers;
    }

    /** @return array<string, RetainedRecoveryScope> */
    public function retainedRecovery(): array
    {
        return $this->recoveryScopes;
    }

    /**
     * Poll recovered root and command scopes without involving the normal worker path.
     *
     * @return list<RecoveryClosureObservation>
     */
    private function pollRecoveryScopes(): array
    {
        /** @var list<RecoveryClosureObservation> $observations */
        $observations = [];
        foreach (array_keys($this->recoveryScopes) as $key) {
            if (!isset($this->recoveryScopes[$key])) {
                continue;
            }
            $scope = $this->recoveryScopes[$key];
            $now = $this->clock->now();
            $attemptStart = $scope->attemptStart;
            $lifecycleStartedAt = $attemptStart === null
                ? $scope->startedAt
                : $attemptStart->monotonicNanoseconds / 1000000000.0;
            if (!$this->shutdownStarted && $lifecycleStartedAt !== null && $scope->lifecyclePolicy !== null) {
                if (!$scope->softSignalled && $scope->lifecyclePolicy->softSeconds !== null && $now >= $lifecycleStartedAt + $scope->lifecyclePolicy->softSeconds) {
                    try {
                        $scope->process->terminateSoft();
                    } catch (Throwable) {
                    }
                    $scope->softSignalled = true;
                }
                if (!$scope->hardSignalled && $scope->lifecyclePolicy->hardSeconds !== null && $now >= $lifecycleStartedAt + $scope->lifecyclePolicy->hardSeconds) {
                    try {
                        $scope->process->terminateHard();
                    } catch (Throwable) {
                    }
                    $scope->hardSignalled = true;
                }
            }
            try {
                $result = $scope->process->poll();
            } catch (Throwable) {
                continue;
            }
            if ($result === null) {
                continue;
            }
            try {
                $proof = $scope->process->scopeProof();
            } catch (Throwable) {
                continue;
            }
            if (!$proof->isClosed()) {
                continue;
            }
            $identity = $scope->identity;
            $start = $scope->attemptStart ?? ($scope->startedAt !== null && $scope->wallStartedAtMicros !== null ? new ExecutionStartObservation($scope->wallStartedAtMicros, (int) round($scope->startedAt * 1000000000)) : null);
            $launchObservation = new ExecutionLaunchObservation($identity->pid, null, 'recovery-quiescent', max(0, (int) $now), ExecutionProcessClosure::confirmed($result->exitCode, 'recovered scope quiescent'), $scope->handle, new ExecutionProcessIdentity($identity->pid, $identity->processGroupId, $identity->sessionId, $identity->startTicks, $identity->bootId), $start);
            $observations[] = new RecoveryClosureObservation($scope->observation->role, $scope->observation->occurrence, $scope->observation->descriptor, $scope->observation->descriptor->rootDescriptor, $scope->observation->executionFence, $scope->observation->recoveryFence, new ExecutionProcessIdentity($identity->pid, $identity->processGroupId, $identity->sessionId, $identity->startTicks, $identity->bootId), $start, $scope->handle, $launchObservation);
            unset($this->recoveryScopes[$key]);
        }
        return $observations;
    }

    private function processIdentityKey(ProcessIdentity|ExecutionProcessIdentity $identity): string
    {
        if ($identity instanceof ProcessIdentity) {
            $processGroup = $identity->processGroupId;
            $session = $identity->sessionId;
        } else {
            $processGroup = $identity->processGroup;
            $session = $identity->session;
        }
        return implode(':', [
            (string) $identity->pid,
            (string) $processGroup,
            (string) $session,
            $identity->startTicks,
            $identity->bootId,
        ]);
    }

    private function recoveryKey(RecoveryObservation $observation, ProcessIdentity $identity): string
    {
        $executionFence = $observation->executionFence;
        $recoveryFence = $observation->recoveryFence;
        return implode(':', [
            (string) $observation->occurrence,
            (string) $executionFence->generation,
            $observation->role,
            $observation->descriptor->marker(),
            $executionFence->token,
            $recoveryFence?->token ?? 'none',
            (string) ($recoveryFence?->generation ?? 0),
            (string) $identity->pid,
            (string) $identity->processGroupId,
            (string) $identity->sessionId,
            $identity->startTicks,
            $identity->bootId,
        ]);
    }

    private function validateRecoveryTiming(?float $startedAt, ?int $wallStartedAtMicros, ?ExecutionStartObservation $attemptStart): void
    {
        if ($startedAt !== null && (!is_finite($startedAt) || $startedAt < 0.0)) {
            throw new InvalidArgumentException('Recovery start time is invalid.');
        }
        if ($wallStartedAtMicros !== null && $wallStartedAtMicros < 0) {
            throw new InvalidArgumentException('Recovery wall start time is invalid.');
        }
        if ($attemptStart !== null && ($attemptStart->wallMicroseconds < 0 || $attemptStart->monotonicNanoseconds < 0)) {
            throw new InvalidArgumentException('Recovery attempt start time is invalid.');
        }
        // The supplied anchor and attempt facts are intentionally independent:
        // a retry has a new attempt boundary but retains the original recovery
        // age anchor. Validate each fact, but do not compare those boundaries.

    }

    private function signalCommand(RetainedWorker $worker, bool $hard): void
    {
        if ($this->commandScopeResolver === null || ($hard ? $worker->commandHardSignalled : $worker->commandSoftSignalled)) {
            return;
        }
        try {
            $command = $this->commandScopeResolver->resolve($worker);
            if ($command !== null) {
                if ($hard) {
                    $command->terminateHard();
                } else {
                    $command->terminateSoft();
                }
            }
        } catch (Throwable) {
            // A failed lookup or signal is not proof of a process outcome.
        }
        // One attempt per lifecycle phase for this retained root. The worker
        // handle remains the sole closure publisher.
        if ($hard) {
            $worker->commandHardSignalled = true;
        } else {
            $worker->commandSoftSignalled = true;
        }
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
            $worker->processIdentity === null ? null : new ExecutionProcessIdentity(
                $worker->processIdentity->pid,
                $worker->processIdentity->processGroupId,
                $worker->processIdentity->sessionId,
                $worker->processIdentity->startTicks,
                $worker->processIdentity->bootId,
            ),
            $this->attemptStartObservation($worker),
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

    private function retainLaunch(ExecutionWorkerLaunchRequest $request, ProcessLaunchResult $launch, ?float $startedAt, ?ExecutionStartObservation $attemptStartObservation = null): ExecutionWorkerLaunchHandle
    {
        if ($launch->identity !== null) {
            $identityKey = $this->processIdentityKey($launch->identity);
            foreach ($this->recoveryScopes as $scope) {
                if ($this->processIdentityKey($scope->identity) === $identityKey) {
                    return $scope->handle;
                }
            }
        }
        $handle = new ExecutionWorkerLaunchHandle('worker-' . $this->nextId++);
        $this->workers[$handle->value] = new RetainedWorker(
            $handle,
            $request,
            $launch->handle,
            $startedAt,
            $launch->wallStartedAtMicros,
            $attemptStartObservation,
            $launch->identity,
            $request->lifecyclePolicy,
        );
        // Publish complete timing only. Incomplete reattachments remain owned
        // but must not become observations with synthesized timing.
        if ($launch->identity !== null && $launch->wallStartedAtMicros !== null && $startedAt !== null) {
            $this->startObservations[] = new ExecutionLaunchObservation(
                $launch->identity->pid,
                null,
                'started',
                max(0, (int) $startedAt),
                null,
                $handle,
                new ExecutionProcessIdentity(
                    $launch->identity->pid,
                    $launch->identity->processGroupId,
                    $launch->identity->sessionId,
                    $launch->identity->startTicks,
                    $launch->identity->bootId,
                ),
                $attemptStartObservation ?? new ExecutionStartObservation($launch->wallStartedAtMicros, (int) round($startedAt * 1000000000)),
            );
        }
        return $handle;
    }

    private function attemptStartObservation(RetainedWorker $worker): ?ExecutionStartObservation
    {
        if ($worker->attemptStartObservation !== null) {
            return $worker->attemptStartObservation;
        }
        if ($worker->processIdentity === null || $worker->wallLaunchedAtMicros === null || $worker->launchedAt === null) {
            return null;
        }
        return new ExecutionStartObservation($worker->wallLaunchedAtMicros, (int) round($worker->launchedAt * 1000000000));
    }

    private function validatedLaunchStartedAt(ProcessLaunchResult $launch): ?float
    {
        if ($launch->startedAt !== null && (!is_finite($launch->startedAt) || $launch->startedAt < 0.0)) {
            throw new InvalidArgumentException('Worker launch start time is invalid.');
        }
        return $launch->startedAt;
    }
}
