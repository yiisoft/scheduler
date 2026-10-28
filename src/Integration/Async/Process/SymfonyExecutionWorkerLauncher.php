<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use Throwable;
use UnexpectedValueException;
use Yiisoft\Scheduler\Integration\Async\{ExecutionWorkerLaunchException, ExecutionWorkerLaunchReceipt, ExecutionWorkerLaunchRequest, ExecutionWorkerLauncherInterface};

/** Thin Symfony adapter; command construction and lifecycle retention remain application-owned. */
final class SymfonyExecutionWorkerLauncher implements ExecutionWorkerLauncherInterface
{
    public function __construct(
        private readonly ExecutionWorkerProcessCommandBuilderInterface $commandBuilder,
        private readonly ExecutionWorkerProcessHandleObserverInterface $handleObserver,
        private readonly ProcessLauncherInterface $processLauncher = new SymfonyProcessLauncher(),
        private readonly ?LaunchDescriptorStoreInterface $descriptorStore = null,
    ) {}

    public function launch(ExecutionWorkerLaunchRequest $request): ExecutionWorkerLaunchReceipt
    {
        try {
            // This is deliberately before command construction and start: a
            // crash after this point remains observable as an incomplete launch.
            if ($this->descriptorStore !== null) {
                $this->descriptorStore->record($request, $request->descriptor);
            }
        } catch (Throwable) {
            throw ExecutionWorkerLaunchException::builderFailure();
        }
        try {
            $processRequest = $this->commandBuilder->build($request);
        } catch (Throwable) {
            throw ExecutionWorkerLaunchException::builderFailure();
        }
        try {
            if ($processRequest->descriptor === null || !$processRequest->descriptor->equals($request->descriptor)) {
                throw new UnexpectedValueException('Process launch descriptor does not match the worker request.');
            }
            $launch = $this->processLauncher->launch($processRequest);
        } catch (Throwable $e) {
            if ($e instanceof UnexpectedValueException) {
                throw ExecutionWorkerLaunchException::builderFailure();
            }
            throw ExecutionWorkerLaunchException::startFailure();
        }
        try {
            // Retention receives the complete launch boundary. In particular,
            // it must not reconstruct timing from the request, which predates
            // owned-scope establishment.
            return new ExecutionWorkerLaunchReceipt($this->handleObserver->retain($request, $launch), $launch);
        } catch (Throwable) {
            // A started process is owned even when registration fails. Do not
            // leave it running, and never let cleanup replace the observer error.
            try {
                $launch->handle->terminateHard();
            } catch (Throwable) {
                // Best effort only: the ownership failure remains authoritative.
            }
            throw ExecutionWorkerLaunchException::observerFailure();
        }
    }
}
