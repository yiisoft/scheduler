<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use Throwable;
use Yiisoft\Schedule\Config\TimeoutPolicy;
use Yiisoft\Schedule\Executor\ShellCommandSpecification;
use Yiisoft\Schedule\Executor\ShellVariableOverrides;
use Yiisoft\Schedule\Executor\ShellVariableResolver;
use Yiisoft\Schedule\Integration\Async\Process\ProcessHandleInterface;
use Yiisoft\Schedule\Integration\Async\Process\ProcessLaunchRequest;
use Yiisoft\Schedule\Integration\Async\Process\ProcessLauncherInterface;
use UnexpectedValueException;

use function is_int;
use function is_string;

/** Executes a claimed literal command without introducing shell parsing. */
final class ShellCommandRuntime implements ExecutionWorkerRuntimeInterface
{
    private const POLL_INTERVAL = 0.001;
    private const OWNERSHIP_UNRESOLVED_REASON = 'worker.ownership_unresolved';

    public function __construct(
        private readonly ShellCommandSpecification $command,
        private readonly ProcessLauncherInterface $launcher,
        private readonly TimeoutPolicy $timeout,
        private readonly ShellVariableOverrides $overrides,
        /** @var array<string, string> */
        private readonly array $environment,
        private readonly ?string $cwd,
        private readonly MonotonicClockInterface $clock,
        private readonly PollWaiterInterface $waiter,
        private readonly ShellVariableResolver $resolver = new ShellVariableResolver(),
    ) {}

    public function execute(): ExecutionOutcome
    {
        try {
            $resolved = $this->resolver->resolve($this->command, $this->overrides, $this->environment);
            /** @var list<string> $argv */
            $argv = [];
            foreach ($resolved->argv as $argument) {
                if (!is_string($argument)) {
                    throw new UnexpectedValueException('Resolved arguments must be strings.');
                }
                $argv[] = $argument;
            }
            $launch = $this->launcher->launch(new ProcessLaunchRequest(
                $resolved->executable,
                $argv,
                $this->cwd,
                $this->environment,
                '',
            ));
            $handle = $launch->handle;
        } catch (Throwable) {
            return new ExecutionOutcome(ExecutionOutcome::FAILED, 'process execution failed');
        }

        try {
            return $this->poll($handle, $launch->startedAt);
        } catch (Throwable) {
            return $this->recoverPostLaunchFailure($handle);
        }
    }

    private function recoverPostLaunchFailure(ProcessHandleInterface $handle): ExecutionOutcome
    {
        try {
            $handle->terminateHard();
        } catch (Throwable) {
            return new ExecutionOutcome(ExecutionOutcome::UNRESOLVED, self::OWNERSHIP_UNRESOLVED_REASON);
        }

        try {
            // Quiescence alone does not prove that Symfony has reaped its
            // process resource. Require the terminal poll as the same
            // observation used by the parent supervisor.
            if ($handle->poll() === null) {
                return new ExecutionOutcome(ExecutionOutcome::UNRESOLVED, self::OWNERSHIP_UNRESOLVED_REASON);
            }
        } catch (Throwable) {
            return new ExecutionOutcome(ExecutionOutcome::UNRESOLVED, self::OWNERSHIP_UNRESOLVED_REASON);
        }

        return new ExecutionOutcome(ExecutionOutcome::FAILED, 'process execution failed');
    }

    private function poll(ProcessHandleInterface $handle, float $started): ExecutionOutcome
    {
        $softDeadline = $this->timeout->softSeconds === null ? null : $started + $this->timeout->softSeconds;
        $hardDeadline = $this->timeout->hardSeconds === null ? null : $started + $this->timeout->hardSeconds;
        $soft = false;
        $hard = false;

        while (true) {
            $result = $handle->poll();
            $observedAt = $this->clock->now();
            if ($result !== null) {
                if (!$hard && $hardDeadline !== null && $observedAt >= $hardDeadline) {
                    return new ExecutionOutcome(ExecutionOutcome::FAILED, 'process hard timeout', ['timeout' => 'hard']);
                }
                if (!$soft && $softDeadline !== null && $observedAt >= $softDeadline) {
                    return new ExecutionOutcome(ExecutionOutcome::CANCELLED, 'process soft timeout', ['timeout' => 'soft']);
                }
                if ($hard) {
                    return new ExecutionOutcome(ExecutionOutcome::FAILED, 'process hard timeout', ['timeout' => 'hard']);
                }
                if ($soft) {
                    return new ExecutionOutcome(ExecutionOutcome::CANCELLED, 'process soft timeout', ['timeout' => 'soft']);
                }
                if ($result->signal !== null) {
                    return new ExecutionOutcome(ExecutionOutcome::FAILED, 'process terminated by signal', ['signal' => $result->signal]);
                }
                if ($result->exitCode === 0) {
                    return new ExecutionOutcome(ExecutionOutcome::SUCCEEDED);
                }
                return new ExecutionOutcome(ExecutionOutcome::FAILED, 'process exited unsuccessfully', is_int($result->exitCode) ? ['exitCode' => $result->exitCode] : []);
            }

            $now = $this->clock->now();
            // Soft is deliberately checked first when deadlines are equal.
            if (!$soft && $softDeadline !== null && $now >= $softDeadline) {
                $handle->terminateSoft();
                $soft = true;
            }
            if (!$hard && $hardDeadline !== null && $now >= $hardDeadline) {
                $handle->terminateHard();
                $hard = true;
            }
            $this->waiter->wait(self::POLL_INTERVAL);
        }
    }
}
