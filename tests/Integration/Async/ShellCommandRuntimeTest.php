<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async;

use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Config\TimeoutPolicy;
use Yiisoft\Scheduler\Executor\ShellCommandSpecification;
use Yiisoft\Scheduler\Executor\ShellVariableOverrides;
use Yiisoft\Scheduler\Integration\Async\MonotonicClockInterface;
use Yiisoft\Scheduler\Integration\Async\PollWaiterInterface;
use Yiisoft\Scheduler\Integration\Async\WorkerLifecyclePolicy;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessHandleInterface;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessLaunchRequest;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessLaunchResult;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessLauncherInterface;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessResult;
use Yiisoft\Scheduler\Integration\Async\ExecutionOutcome;
use Yiisoft\Scheduler\Integration\Async\ShellCommandRuntime;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchDescriptor;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessIdentity;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessScopeProof;

use function count;
use function strlen;

final class FakeClock implements MonotonicClockInterface
{
    private int $index = 0;

    public function __construct(private readonly array $times, private readonly ?int $throwOnCall = null) {}

    public function now(): float
    {
        if ($this->throwOnCall === $this->index) {
            ++$this->index;
            throw new RuntimeException('clock failure: secret-clock-detail');
        }

        return $this->times[min($this->index++, count($this->times) - 1)] ?? 0.0;
    }
}

final class FakeWaiter implements PollWaiterInterface
{
    public function __construct(private readonly bool $fails = false) {}

    public function wait(float $seconds): void
    {
        if ($this->fails) {
            throw new RuntimeException('wait failure');
        }
    }
}

final class FakeProcessHandle implements ProcessHandleInterface
{
    public int $softTerminations = 0;
    public int $hardTerminations = 0;

    public function __construct(
        private readonly ?ProcessResult $result,
        private readonly bool $pollFails = false,
        private readonly bool $terminationFails = false,
        private readonly bool $waitForTermination = false,
        private readonly bool $quiescent = true,
        private readonly bool $quiescenceFails = false,
    ) {}

    public function scopeProof(): ProcessScopeProof
    {
        return ProcessScopeProof::closedTerminalFromRetainedPoll(new ExecutionLaunchDescriptor('test', 1, 'test'), new ProcessIdentity(10, 10, 10, '1', 'boot', new ExecutionLaunchDescriptor('test', 1, 'test')));
    }

    public function isRunning(): bool
    {
        return false;
    }

    public function isQuiescent(): bool
    {
        if ($this->quiescenceFails) {
            throw new RuntimeException('quiescence failure: secret-process-detail');
        }

        return $this->quiescent && !$this->isRunning();
    }

    public function poll(): ?ProcessResult
    {
        if ($this->pollFails) {
            throw new RuntimeException('poll failure');
        }
        if ($this->waitForTermination && $this->softTerminations === 0 && $this->hardTerminations === 0) {
            return null;
        }
        return $this->result;
    }

    public function terminateSoft(): void
    {
        if ($this->terminationFails) {
            throw new RuntimeException('termination failure');
        }
        ++$this->softTerminations;
    }

    public function terminateHard(): void
    {
        ++$this->hardTerminations;
        if ($this->terminationFails) {
            throw new RuntimeException('termination failure: secret-termination-detail');
        }
    }
}

final class ShellCommandRuntimeTest extends TestCase
{
    public function testLaunchContractCarriesAuthoritativeStartTimestamp(): void
    {
        self::assertTrue(class_exists('Yiisoft\\Scheduler\\Integration\\Async\\Process\\ProcessLaunchResult'));
        $launchResult = new ReflectionClass('Yiisoft\\Scheduler\\Integration\\Async\\Process\\ProcessLaunchResult');
        self::assertTrue($launchResult->hasProperty('handle'));
        self::assertTrue($launchResult->hasProperty('startedAt'));
        self::assertSame(ProcessHandleInterface::class, $launchResult->getProperty('handle')->getType()->getName());
        self::assertSame('float', $launchResult->getProperty('startedAt')->getType()->getName());
        self::assertTrue($launchResult->getProperty('handle')->isReadOnly());
        self::assertTrue($launchResult->getProperty('startedAt')->isReadOnly());

        $launch = new ReflectionMethod(ProcessLauncherInterface::class, 'launch');
        self::assertSame('Yiisoft\\Scheduler\\Integration\\Async\\Process\\ProcessLaunchResult', (string) $launch->getReturnType());
    }

    public function testDelayedLauncherReturnCarriesAuthoritativeStartedAt(): void
    {
        $handle = new FakeProcessHandle(new ProcessResult(0));
        $clock = new FakeClock([3.0, 17.25]);
        $beforeLaunch = $clock->now();
        $launcher = new class ($handle, $clock) implements ProcessLauncherInterface {
            public function __construct(private readonly ProcessHandleInterface $handle, private readonly MonotonicClockInterface $clock) {}

            public function launch(ProcessLaunchRequest $request): ProcessLaunchResult
            {
                // Model work occurring before the launcher returns without sleeping.
                $startedAt = $this->clock->now();
                return new ProcessLaunchResult($this->handle, $startedAt);
            }
        };

        $launch = $launcher->launch(new ProcessLaunchRequest('/bin/true', [], null, [], ''));

        self::assertSame(3.0, $beforeLaunch);
        self::assertSame($handle, $launch->handle);
        self::assertSame(17.25, $launch->startedAt);
    }

    public function testDelayedLauncherReturnUsesStartedAtForTimeoutOracle(): void
    {
        $clock = new class implements MonotonicClockInterface {
            public float $time = 0.0;

            public function now(): float
            {
                return $this->time;
            }
        };
        $handle = new class ($clock) implements ProcessHandleInterface {
            public function scopeProof(): ProcessScopeProof
            {
                return ProcessScopeProof::closedTerminalFromRetainedPoll(new ExecutionLaunchDescriptor('test', 1, 'test'), new ProcessIdentity(10, 10, 10, '1', 'boot', new ExecutionLaunchDescriptor('test', 1, 'test')));
            }


            public int $polls = 0;
            public int $softTerminations = 0;

            public function __construct(private readonly object $clock) {}

            public function isRunning(): bool
            {
                return true;
            }

            public function isQuiescent(): bool
            {
                return false;
            }

            public function poll(): ?ProcessResult
            {
                ++$this->polls;
                // It remains running until the timeout is observed. A faulty
                // launcher-return clock reaches natural completion at 12.0.
                if ($this->softTerminations > 0 || $this->clock->time >= 12.0) {
                    return new ProcessResult(0);
                }
                return null;
            }

            public function terminateSoft(): void
            {
                ++$this->softTerminations;
            }

            public function terminateHard(): void {}
        };
        $launcher = new class ($handle, $clock) implements ProcessLauncherInterface {
            public function __construct(private readonly ProcessHandleInterface $handle, private readonly object $clock) {}

            public function launch(ProcessLaunchRequest $request): ProcessLaunchResult
            {
                // The process started at zero, while preparation and the
                // launcher return happen at nine.
                $startedAt = 0.0;
                $this->clock->time = 9.0;
                return new ProcessLaunchResult($this->handle, $startedAt);
            }
        };
        $waiter = new class ($clock) implements PollWaiterInterface {
            public int $calls = 0;

            public function __construct(private readonly object $clock) {}

            public function wait(float $seconds): void
            {
                if (++$this->calls > 8) {
                    throw new RuntimeException('bounded polling exceeded');
                }
                $this->clock->time += 1.0;
            }
        };

        $outcome = (new ShellCommandRuntime(
            new ShellCommandSpecification('/bin/true'),
            $launcher,
            new TimeoutPolicy(10.0),
            new ShellVariableOverrides(),
            [],
            null,
            $clock,
            $waiter,
        ))->execute();

        // Using the post-launch clock (9.0) would complete at 12.0 instead;
        // the authoritative start at 0.0 must cause soft timeout at 10.0.
        self::assertSame(ExecutionOutcome::CANCELLED, $outcome->status);
        self::assertSame('process soft timeout', $outcome->reason);
        self::assertSame(1, $handle->softTerminations);
        self::assertSame(3, $handle->polls);
        self::assertSame(2, $waiter->calls);
    }

    public function testRuntimeDeadlineUsesLaunchStartedAtNotLauncherReturnClock(): void
    {
        $handle = new FakeProcessHandle(new ProcessResult(0), false, false, true);
        $clock = new FakeClock([0.75, 1.1]);
        $launcher = new class ($handle, $clock) implements ProcessLauncherInterface {
            public function __construct(private readonly ProcessHandleInterface $handle, private readonly MonotonicClockInterface $clock) {}

            public function launch(ProcessLaunchRequest $request): ProcessLaunchResult
            {
                // The launcher returns later than the authoritative process start.
                $this->clock->now();
                return new ProcessLaunchResult($this->handle, 0.0);
            }
        };
        $runtime = new ShellCommandRuntime(
            new ShellCommandSpecification('/bin/true'),
            $launcher,
            new TimeoutPolicy(1.0),
            new ShellVariableOverrides(),
            [],
            null,
            $clock,
            new FakeWaiter(),
        );

        self::assertSame(ExecutionOutcome::CANCELLED, $runtime->execute()->status);
        self::assertSame(1, $handle->softTerminations);
    }

    public function testWorkerLifecyclePolicyIsSeparateFromCommandTimeoutPolicy(): void
    {
        $policy = new ReflectionClass(WorkerLifecyclePolicy::class);
        $runtime = new ReflectionClass(ShellCommandRuntime::class);
        self::assertTrue($policy->isFinal());
        self::assertNotSame(TimeoutPolicy::class, $policy->getName());
        self::assertFalse($policy->hasProperty('remainingBudget'));
        self::assertFalse($runtime->hasMethod('executeWithRemainingBudget'));
    }

    public function testLaunchesLiteralArgumentsAndEnvironmentSeparately(): void
    {
        $request = null;
        $runtime = new ShellCommandRuntime(
            new ShellCommandSpecification('/bin/echo', ['${VALUE}', 'a; b'], ['VALUE' => 'fallback']),
            new class ($request) implements ProcessLauncherInterface {
                public function __construct(private ?ProcessLaunchRequest &$request) {}

                public function launch(ProcessLaunchRequest $request): ProcessLaunchResult
                {
                    $this->request = $request;
                    $handle = new class implements ProcessHandleInterface {
                        public function scopeProof(): ProcessScopeProof
                        {
                            return ProcessScopeProof::closedTerminalFromRetainedPoll(new ExecutionLaunchDescriptor('test', 1, 'test'), new ProcessIdentity(10, 10, 10, '1', 'boot', new ExecutionLaunchDescriptor('test', 1, 'test')));
                        }

                        public function isRunning(): bool
                        {
                            return false;
                        }

                        public function isQuiescent(): bool
                        {
                            return true;
                        }

                        public function poll(): ?ProcessResult
                        {
                            return new ProcessResult(0);
                        }

                        public function terminateSoft(): void {}

                        public function terminateHard(): void {}
                    };
                    return new ProcessLaunchResult($handle, 0.0);
                }
            },
            new TimeoutPolicy(),
            new ShellVariableOverrides(['VALUE' => 'override']),
            ['VALUE' => 'environment', 'PATH' => '/bin'],
            null,
            new class implements MonotonicClockInterface {
                public function now(): float
                {
                    return 0.0;
                }
            },
            new class implements PollWaiterInterface {
                public function wait(float $seconds): void {}
            },
        );

        self::assertSame('succeeded', $runtime->execute()->status);
        self::assertNotNull($request);
        self::assertSame('/bin/echo', $request->executable);
        self::assertSame(['override', 'a; b'], $request->argv);
        self::assertSame(['VALUE' => 'environment', 'PATH' => '/bin'], $request->environment);
    }

    public function testMissingVariableDoesNotLaunch(): void
    {
        $launches = 0;
        $runtime = new ShellCommandRuntime(
            new ShellCommandSpecification('/bin/echo', ['${MISSING}']),
            new class ($launches) implements ProcessLauncherInterface {
                public function __construct(private int &$launches) {}

                public function launch(ProcessLaunchRequest $request): ProcessLaunchResult
                {
                    $this->launches++;
                    throw new LogicException();
                }
            },
            new TimeoutPolicy(),
            new ShellVariableOverrides(),
            [],
            null,
            new class implements MonotonicClockInterface {
                public function now(): float
                {
                    return 0.0;
                }
            },
            new class implements PollWaiterInterface {
                public function wait(float $seconds): void {}
            },
        );

        $outcome = $runtime->execute();
        self::assertSame('failed', $outcome->status);
        self::assertSame(0, $launches);
        self::assertStringNotContainsString('MISSING', (string) $outcome->reason);
    }

    public function testResultBeforeDeadlineSucceeds(): void
    {
        $handle = new FakeProcessHandle(new ProcessResult(0, null, 'output', 'diagnostic'));
        $outcome = $this->runtime($handle, new TimeoutPolicy(1.0, 2.0), [0.0, 0.0, 0.0]);

        self::assertSame(ExecutionOutcome::SUCCEEDED, $outcome->status);
        self::assertSame([], $outcome->metadata);
        self::assertSame(0, $handle->softTerminations);
        self::assertSame(0, $handle->hardTerminations);
    }

    public function testSoftTimeoutCancelsAndTerminatesOnce(): void
    {
        $handle = new FakeProcessHandle(new ProcessResult(0), false, false, true);
        $outcome = $this->runtime($handle, new TimeoutPolicy(1.0), [0.0, 0.0, 1.0, 1.0]);

        self::assertSame(ExecutionOutcome::CANCELLED, $outcome->status);
        self::assertSame('process soft timeout', $outcome->reason);
        self::assertSame(1, $handle->softTerminations);
        self::assertSame(0, $handle->hardTerminations);
    }

    public function testHardTimeoutFailsAndTerminatesOnce(): void
    {
        $handle = new FakeProcessHandle(new ProcessResult(0), false, false, true);
        $outcome = $this->runtime($handle, new TimeoutPolicy(null, 1.0), [0.0, 0.0, 1.0, 1.0]);

        self::assertSame(ExecutionOutcome::FAILED, $outcome->status);
        self::assertSame('process hard timeout', $outcome->reason);
        self::assertSame(0, $handle->softTerminations);
        self::assertSame(1, $handle->hardTerminations);
    }

    public function testEqualDeadlinesTerminateSoftAndHardOnce(): void
    {
        $handle = new FakeProcessHandle(new ProcessResult(0), false, false, true);
        $outcome = $this->runtime($handle, new TimeoutPolicy(1.0, 1.0), [0.0, 0.0, 1.0, 1.0]);

        self::assertSame(ExecutionOutcome::FAILED, $outcome->status);
        self::assertSame(1, $handle->softTerminations);
        self::assertSame(1, $handle->hardTerminations);
    }

    public function testPreparationDelayDoesNotShortenSoftOrHardCommandTimeout(): void
    {
        foreach ([
            [new TimeoutPolicy(5.0), ExecutionOutcome::CANCELLED, 'process soft timeout', 'softTerminations'],
            [new TimeoutPolicy(null, 8.0), ExecutionOutcome::FAILED, 'process hard timeout', 'hardTerminations'],
        ] as [$policy, $status, $reason, $termination]) {
            $clock = new class implements MonotonicClockInterface {
                public float $time = 0.0;

                public function now(): float
                {
                    return $this->time;
                }
            };
            $handle = new class ($clock) implements ProcessHandleInterface {
                public function scopeProof(): ProcessScopeProof
                {
                    return ProcessScopeProof::closedTerminalFromRetainedPoll(new ExecutionLaunchDescriptor('test', 1, 'test'), new ProcessIdentity(10, 10, 10, '1', 'boot', new ExecutionLaunchDescriptor('test', 1, 'test')));
                }


                public int $softTerminations = 0;
                public int $hardTerminations = 0;

                public function __construct(private readonly object $clock) {}

                public function isRunning(): bool
                {
                    return true;
                }

                public function isQuiescent(): bool
                {
                    return false;
                }

                public function poll(): ?ProcessResult
                {
                    return $this->softTerminations > 0 || $this->hardTerminations > 0 ? new ProcessResult(0) : null;
                }

                public function terminateSoft(): void
                {
                    ++$this->softTerminations;
                }

                public function terminateHard(): void
                {
                    ++$this->hardTerminations;
                }
            };
            $launcher = new class ($clock, $handle) implements ProcessLauncherInterface {
                public function __construct(private readonly object $clock, private readonly ProcessHandleInterface $handle) {}

                public function launch(ProcessLaunchRequest $request): ProcessLaunchResult
                {
                    // Preparation consumes time before launch; 100.0 is the
                    // actual command start timestamp returned by the launcher.
                    $this->clock->time = 90.0;
                    $startedAt = 100.0;
                    $this->clock->time = $startedAt;
                    return new ProcessLaunchResult($this->handle, $startedAt);
                }
            };
            $waiter = new class ($clock) implements PollWaiterInterface {
                public int $calls = 0;

                public function __construct(private readonly object $clock) {}

                public function wait(float $seconds): void
                {
                    if (++$this->calls > 16) {
                        throw new RuntimeException('bounded polling exceeded');
                    }
                    $this->clock->time += 1.0;
                }
            };

            $outcome = (new ShellCommandRuntime(
                new ShellCommandSpecification('/bin/true'),
                $launcher,
                $policy,
                new ShellVariableOverrides(),
                [],
                null,
                $clock,
                $waiter,
            ))->execute();

            self::assertSame($status, $outcome->status);
            self::assertSame($reason, $outcome->reason);
            self::assertSame(1, $handle->{$termination});
            self::assertSame(0, $handle->{$termination === 'softTerminations' ? 'hardTerminations' : 'softTerminations'});
            self::assertSame($termination === 'softTerminations' ? 6 : 9, $waiter->calls);
        }
    }

    public function testNullCommandTimeoutRemainsUnlimited(): void
    {
        $handle = new FakeProcessHandle(new ProcessResult(0));
        self::assertSame(ExecutionOutcome::SUCCEEDED, $this->runtime($handle, new TimeoutPolicy())->status);
    }

    public function testNoTimeoutAndExitFailuresAndSignal(): void
    {
        $cases = [
            [new ProcessResult(0), ExecutionOutcome::SUCCEEDED],
            [new ProcessResult(7), ExecutionOutcome::FAILED],
            [new ProcessResult(null, 9), ExecutionOutcome::FAILED],
        ];
        foreach ($cases as [$result, $status]) {
            self::assertSame($status, $this->runtime(new FakeProcessHandle($result), new TimeoutPolicy())->status);
        }
    }

    public function testPostLaunchFailuresHardCleanUpAndRemainBoundedWhenQuiescent(): void
    {
        $handles = [
            new FakeProcessHandle(null, true),
            new FakeProcessHandle(null),
            new FakeProcessHandle(null),
        ];
        $outcomes = [
            $this->runtime($handles[0], new TimeoutPolicy(), [0.0, 0.0]),
            $this->runtime($handles[1], new TimeoutPolicy(), [0.0, 0.0], true),
            $this->runtime($handles[2], new TimeoutPolicy(), [0.0, 0.0], false, 1),
        ];

        foreach ($outcomes as $i => $outcome) {
            self::assertSame(ExecutionOutcome::UNRESOLVED, $outcome->status);
            self::assertSame('worker.ownership_unresolved', $outcome->reason);
            self::assertSame(1, $handles[$i]->hardTerminations);
            self::assertLessThanOrEqual(4096, strlen((string) $outcome->reason));
            self::assertStringNotContainsString('secret-', (string) $outcome->reason);
        }
    }

    public function testCleanupFailureOrNonQuiescenceReturnsUnresolvedOwnershipSignal(): void
    {
        $cleanupFailure = new FakeProcessHandle(null, true, true);
        $outcome = $this->runtime($cleanupFailure, new TimeoutPolicy(), [0.0, 0.0]);
        self::assertSame(ExecutionOutcome::UNRESOLVED, $outcome->status);
        self::assertNotNull($outcome->reason);
        self::assertLessThanOrEqual(4096, strlen($outcome->reason));
        self::assertStringNotContainsString('secret-', $outcome->reason);
        self::assertSame(1, $cleanupFailure->hardTerminations);

        $notQuiescent = new FakeProcessHandle(null, true, false, false, false);
        $outcome = $this->runtime($notQuiescent, new TimeoutPolicy(), [0.0, 0.0]);
        self::assertSame(ExecutionOutcome::UNRESOLVED, $outcome->status);
        self::assertSame(1, $notQuiescent->hardTerminations);
    }

    public function testLaunchPollAndTerminationFailuresAreBoundedFailures(): void
    {
        $launcher = new class implements ProcessLauncherInterface {
            public function launch(ProcessLaunchRequest $request): ProcessLaunchResult
            {
                throw new RuntimeException('launch detail');
            }
        };
        $runtime = new ShellCommandRuntime(
            new ShellCommandSpecification('/bin/true'),
            $launcher,
            new TimeoutPolicy(),
            new ShellVariableOverrides(),
            [],
            null,
            new FakeClock([0.0]),
            new FakeWaiter(),
        );
        $outcome = $runtime->execute();
        self::assertSame(ExecutionOutcome::FAILED, $outcome->status);
        self::assertSame('process execution failed', $outcome->reason);

        $pollFailure = $this->runtime(new FakeProcessHandle(null, true), new TimeoutPolicy(), [0.0, 0.0]);
        self::assertSame('worker.ownership_unresolved', $pollFailure->reason);

        $terminationFailure = $this->runtime(new FakeProcessHandle(new ProcessResult(0), false, true, true), new TimeoutPolicy(1.0), [0.0, 0.0, 1.0]);
        self::assertSame(ExecutionOutcome::UNRESOLVED, $terminationFailure->status);
        self::assertSame('worker.ownership_unresolved', $terminationFailure->reason);

        $waiterFailure = new ShellCommandRuntime(
            new ShellCommandSpecification('/bin/true'),
            new class implements ProcessLauncherInterface {
                public function launch(ProcessLaunchRequest $request): ProcessLaunchResult
                {
                    return new ProcessLaunchResult(new FakeProcessHandle(null), 0.0);
                }
            },
            new TimeoutPolicy(),
            new ShellVariableOverrides(),
            [],
            null,
            new FakeClock([0.0, 0.0, 0.0]),
            new FakeWaiter(true),
        );
        self::assertSame('worker.ownership_unresolved', $waiterFailure->execute()->reason);
    }

    private function runtime(FakeProcessHandle $handle, TimeoutPolicy $timeout, array $times = [0.0, 0.0], bool $waiterFails = false, ?int $clockThrowsOnCall = null): ExecutionOutcome
    {
        return (new ShellCommandRuntime(
            new ShellCommandSpecification('/bin/true'),
            new class ($handle) implements ProcessLauncherInterface {
                public function __construct(private readonly FakeProcessHandle $handle) {}

                public function launch(ProcessLaunchRequest $request): ProcessLaunchResult
                {
                    return new ProcessLaunchResult($this->handle, 0.0);
                }
            },
            $timeout,
            new ShellVariableOverrides(),
            [],
            null,
            new FakeClock($times, $clockThrowsOnCall),
            new FakeWaiter($waiterFails),
        ))->execute();
    }
}
