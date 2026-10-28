<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use RuntimeException;
use Yiisoft\Scheduler\Integration\Async\{ExecutionDispatchResult,ExecutionFence,ExecutionLaunchDescriptor,ExecutionLaunchObservation,ExecutionLedgerCommandInterface,ExecutionLedgerRecord,ExecutionProcessClosure,ExecutionProcessIdentity,ExecutionStartObservation,ExecutionWorkerCapability};

/** Adds the command's durable ownership boundary around an ordinary launcher. */
final class ExecutionOwnedCommandLauncher implements ProcessLauncherInterface
{
    public function __construct(
        private readonly ExecutionLedgerCommandInterface $ledger,
        private readonly ExecutionWorkerCapability $capability,
        private readonly ExecutionLedgerRecord $claimedRecord,
        private readonly ExecutionLaunchDescriptor $rootDescriptor,
        private readonly ProcessLauncherInterface $delegate,
    ) {}

    public function launch(ProcessLaunchRequest $request): ProcessLaunchResult
    {
        $commandDescriptor = new ExecutionLaunchDescriptor(
            $this->rootDescriptor->executionId,
            $this->rootDescriptor->generation,
            rootDescriptor: $this->rootDescriptor,
            occurrence: $this->capability->occurrence,
            role: ExecutionLaunchDescriptor::ROLE_COMMAND,
        );
        $wallUs = (int) floor(microtime(true) * 1000000);
        $at = intdiv($wallUs, 1000000);
        $fence = new ExecutionFence($this->capability->generation, $this->capability->fenceToken);
        $sourceIdentity = $this->claimedRecord->sourceIdentity ?? throw new RuntimeException('Claimed execution source identity is missing.');
        $prepared = $this->ledger->prepareCommandUnderLock(
            $this->capability->occurrence,
            $fence,
            $this->capability->recoveryFence,
            $sourceIdentity,
            $this->rootDescriptor,
            $commandDescriptor,
            $at,
            $wallUs,
        );
        $scope = $prepared->record?->commandScope;
        if ($prepared->status !== ExecutionDispatchResult::ACCEPTED || $scope === null) {
            throw new RuntimeException($prepared->reason ?? 'Command preparation was not accepted.');
        }
        $result = null;
        $dispatch = $this->ledger->launchCommandUnderLock(
            $this->capability->occurrence,
            $fence,
            $this->capability->recoveryFence,
            $sourceIdentity,
            $this->rootDescriptor,
            $scope,
            $at,
            function () use ($request, $commandDescriptor, &$result): ExecutionLaunchObservation {
                $result = $this->delegate->launch(new ProcessLaunchRequest(
                    $request->executable,
                    $request->argv,
                    $request->cwd,
                    $request->environment,
                    $request->protocolInput,
                    $commandDescriptor,
                ));
                if ($result->startedAt === null
                    || $result->wallStartedAtMicros === null
                    || $result->identity === null
                ) {
                    throw new RuntimeException('Command launcher returned an incomplete start receipt.');
                }
                $startedAt = $result->startedAt;
                $wallStartedAtMicros = $result->wallStartedAtMicros;
                $launchIdentity = $result->identity;
                $identity = new ExecutionProcessIdentity(
                    $launchIdentity->pid,
                    $launchIdentity->processGroupId,
                    $launchIdentity->sessionId,
                    $launchIdentity->startTicks,
                    $launchIdentity->bootId,
                );
                return new ExecutionLaunchObservation(
                    $identity->pid,
                    null,
                    'started',
                    intdiv($wallStartedAtMicros, 1000000),
                    startObservation: new ExecutionStartObservation($wallStartedAtMicros, (int) floor($startedAt * 1000000000)),
                    processIdentity: $identity,
                );
            },
            $wallUs,
        );
        if ($dispatch->status !== ExecutionDispatchResult::ACCEPTED) {
            throw new RuntimeException($dispatch->reason ?? 'Command launch was not accepted.');
        }
        if ($result === null) {
            throw new RuntimeException('Command launcher returned an incomplete start receipt.');
        }
        if ($result->startedAt === null || $result->wallStartedAtMicros === null || $result->identity === null) {
            throw new RuntimeException('Command launcher returned an incomplete start receipt.');
        }
        $startedAt = $result->startedAt;
        $wallStartedAtMicros = $result->wallStartedAtMicros;
        $launchIdentity = $result->identity;
        return new ProcessLaunchResult(
            new ExecutionOwnedCommandHandle(
                $result->handle,
                $this->ledger,
                $this->capability,
                $this->claimedRecord->sourceIdentity ?? throw new RuntimeException('Claimed execution source identity is missing.'),
                $this->rootDescriptor,
                $commandDescriptor,
                $launchIdentity,
                $wallStartedAtMicros,
                new ExecutionStartObservation($wallStartedAtMicros, (int) floor($startedAt * 1000000000)),
            ),
            $startedAt,
            $wallStartedAtMicros,
            $launchIdentity,
        );
    }
}

/** Poll decorator which publishes the terminal command fact outside the process poll. */
final class ExecutionOwnedCommandHandle implements ProcessHandleInterface
{
    private bool $published = false;

    public function __construct(
        private readonly ProcessHandleInterface $delegate,
        private readonly ExecutionLedgerCommandInterface $ledger,
        private readonly ExecutionWorkerCapability $capability,
        private readonly string $sourceIdentity,
        private readonly ExecutionLaunchDescriptor $rootDescriptor,
        private readonly ExecutionLaunchDescriptor $commandDescriptor,
        private readonly ?ProcessIdentity $identity,
        private readonly ?int $wallStartedAtMicros,
        private readonly ?ExecutionStartObservation $startObservation,
    ) {}

    public function scopeProof(): ProcessScopeProof
    {
        return $this->delegate->scopeProof();
    }

    public function isRunning(): bool
    {
        return $this->delegate->isRunning();
    }

    public function isQuiescent(): bool
    {
        return $this->delegate->isQuiescent();
    }

    public function terminateSoft(): void
    {
        $this->delegate->terminateSoft();
    }

    public function terminateHard(): void
    {
        $this->delegate->terminateHard();
    }

    public function poll(): ?ProcessResult
    {
        $result = $this->delegate->poll();
        if ($result === null || $this->published) {
            return $result;
        }
        $identity = $this->identity;
        if ($identity === null || $this->wallStartedAtMicros === null) {
            return $result;
        }
        $this->ledger->observeCommandUnderLock(
            $this->capability->occurrence,
            new ExecutionFence($this->capability->generation, $this->capability->fenceToken),
            $this->capability->recoveryFence,
            $this->sourceIdentity,
            $this->rootDescriptor,
            $this->commandDescriptor,
            new ExecutionLaunchObservation(
                $identity->pid,
                null,
                'closed',
                max(0, (int) floor(microtime(true))),
                ExecutionProcessClosure::confirmed($result->exitCode, 'process exited'),
                processIdentity: new ExecutionProcessIdentity($identity->pid, $identity->processGroupId, $identity->sessionId, $identity->startTicks, $identity->bootId),
                startObservation: $this->startObservation,
            ),
        );
        $this->published = true;
        return $result;
    }
}
