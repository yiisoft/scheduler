<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async\Process;

use Throwable;
use Yiisoft\Schedule\Integration\Async\{ExecutionWorkerLaunchException, ExecutionWorkerLaunchHandle, ExecutionWorkerLaunchRequest, ExecutionWorkerLauncherInterface};

/** Thin Symfony adapter; command construction and lifecycle retention remain application-owned. */
final class SymfonyExecutionWorkerLauncher implements ExecutionWorkerLauncherInterface
{
    public function __construct(
        private readonly ExecutionWorkerProcessCommandBuilderInterface $commandBuilder,
        private readonly ExecutionWorkerProcessHandleObserverInterface $handleObserver,
        private readonly ProcessLauncherInterface $processLauncher = new SymfonyProcessLauncher(),
    ) {}

    public function launch(ExecutionWorkerLaunchRequest $request): ExecutionWorkerLaunchHandle
    {
        try {
            $processRequest = $this->commandBuilder->build($request);
        } catch (Throwable) {
            throw ExecutionWorkerLaunchException::builderFailure();
        }
        try {
            $launch = $this->processLauncher->launch($processRequest);
            $handle = $launch->handle;
        } catch (Throwable) {
            throw ExecutionWorkerLaunchException::startFailure();
        }
        try {
            return $this->handleObserver->retain($request, $handle);
        } catch (Throwable) {
            throw ExecutionWorkerLaunchException::observerFailure();
        }
    }
}
