<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Planner;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Yiisoft\Scheduler\Config\TimeoutPolicy;
use Yiisoft\Scheduler\Integration\Async\{ExecutionOccurrenceKey, ExecutionWorkerCapability, ExecutionWorkerLaunchRequest, MonotonicClockInterface, PollWaiterInterface, WorkerLifecyclePolicy};
use Yiisoft\Scheduler\Integration\Async\Process\ProcessHandleInterface;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessResult;
use Yiisoft\Scheduler\Planner\ParentLifecycleSupervisor;
use Closure;

final class ParentLifecycleSupervisorTest extends TestCase
{
    public function testWorkerLifecyclePolicyIsTheParentSupervisionPolicy(): void
    {
        $policy = new WorkerLifecyclePolicy(1.25, 2.5);
        $type = new ReflectionClass(WorkerLifecyclePolicy::class);
        $request = new ReflectionClass(ExecutionWorkerLaunchRequest::class);

        self::assertSame(1.25, $policy->softSeconds);
        self::assertSame(2.5, $policy->hardSeconds);
        self::assertSame(WorkerLifecyclePolicy::class, $request->getProperty('lifecyclePolicy')->getType()->getName());
        self::assertTrue($request->getProperty('lifecyclePolicy')->getType()->allowsNull());
        self::assertNotSame(TimeoutPolicy::class, $request->getProperty('lifecyclePolicy')->getType()->getName());
        self::assertTrue($type->isFinal());
    }

    public function testNullLifecyclePolicySkipsDeadlineSignalsAndIsRetained(): void
    {
        $clock = new class implements MonotonicClockInterface {
            public function now(): float
            {
                return 100;
            }
        };
        $waiter = new class implements PollWaiterInterface {
            public function wait(float $seconds): void {}
        };
        $handle = new class implements ProcessHandleInterface {
            public int $soft = 0;
            public int $hard = 0;

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
                return null;
            }

            public function terminateSoft(): void
            {
                ++$this->soft;
            }

            public function terminateHard(): void
            {
                ++$this->hard;
            }
        };
        $supervisor = new ParentLifecycleSupervisor($clock, $waiter);
        $launch = $supervisor->retain(new ExecutionWorkerLaunchRequest($this->capability(), null, 0), $handle);

        $supervisor->poll();

        self::assertNull($supervisor->retained()[$launch->value]->lifecyclePolicy);
        self::assertSame(0, $handle->soft);
        self::assertSame(0, $handle->hard);
    }

    public function testExitDoesNotReleaseBeforeConfirmedQuiescenceAndSignalsOnce(): void
    {
        $clock = new class implements MonotonicClockInterface {
            public float $time = 0;

            public function now(): float
            {
                return $this->time;
            }
        };
        $waiter = new class implements PollWaiterInterface {
            public function wait(float $seconds): void {}
        };
        $handle = new class implements ProcessHandleInterface {
            public int $soft = 0;
            public int $hard = 0;
            public bool $quiet = false;

            public function isRunning(): bool
            {
                return !$this->quiet;
            }

            public function isQuiescent(): bool
            {
                return $this->quiet;
            }

            public function poll(): ?ProcessResult
            {
                return $this->quiet ? new ProcessResult(0) : null;
            }

            public function terminateSoft(): void
            {
                $this->soft++;
            }

            public function terminateHard(): void
            {
                $this->hard++;
            }
        };
        $supervisor = new ParentLifecycleSupervisor($clock, $waiter);
        // Retention is deliberately delayed: deadlines belong to launch, not observation.
        $clock->time = 3;
        $launch = $supervisor->retain(new ExecutionWorkerLaunchRequest($this->capability(), new WorkerLifecyclePolicy(2, 2), 0), $handle);

        $supervisor->poll();
        self::assertTrue($supervisor->hasUnresolvedWorkers());
        self::assertSame(1, $handle->soft);
        self::assertSame(1, $handle->hard);
        $supervisor->poll();
        self::assertSame(1, $handle->soft);
        self::assertSame(1, $handle->hard);

        $handle->quiet = true;
        $observations = $supervisor->poll();
        self::assertFalse($supervisor->hasUnresolvedWorkers());
        self::assertTrue($observations[0]->closure?->isConfirmed());
        self::assertSame($launch->value, $observations[0]->launchHandle?->value);
    }

    public function testConfirmedExitRetainsBoundedWorkerDiagnostic(): void
    {
        $clock = new class implements MonotonicClockInterface {
            public function now(): float
            {
                return 0;
            }
        };
        $waiter = new class implements PollWaiterInterface {
            public function wait(float $seconds): void {}
        };
        $handle = new class implements ProcessHandleInterface {
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
                return new ProcessResult(1, stderr: "Could not open input file: run\n");
            }

            public function terminateSoft(): void {}

            public function terminateHard(): void {}
        };
        $supervisor = new ParentLifecycleSupervisor($clock, $waiter);
        $supervisor->retain(new ExecutionWorkerLaunchRequest($this->capability(), new WorkerLifecyclePolicy(), 0), $handle);

        $observation = $supervisor->poll()[0];
        self::assertSame('worker exit code 1: Could not open input file: run ', $observation->error);
        self::assertSame(1, $observation->closure?->exitCode);
    }

    public function testQuiescentScopeIsRetainedUntilPollReturnsAResult(): void
    {
        $clock = new class implements MonotonicClockInterface {
            public function now(): float
            {
                return 0;
            }
        };
        $waiter = new class implements PollWaiterInterface {
            public function wait(float $seconds): void {}
        };
        $handle = new class implements ProcessHandleInterface {
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
                return null;
            }

            public function terminateSoft(): void {}

            public function terminateHard(): void {}
        };
        $supervisor = new ParentLifecycleSupervisor($clock, $waiter);
        $supervisor->retain(new ExecutionWorkerLaunchRequest($this->capability(), new WorkerLifecyclePolicy(), 0), $handle);

        self::assertSame([], $supervisor->poll());
        self::assertTrue($supervisor->hasUnresolvedWorkers());
    }

    public function testShutdownSendsSoftBeforeHardAtGraceDeadline(): void
    {
        $clock = new class implements MonotonicClockInterface {
            public float $time = 0;

            public function now(): float
            {
                return $this->time;
            }
        };
        $waiter = new class (static function (float $seconds) use ($clock): void {
            $clock->time += $seconds;
        }) implements PollWaiterInterface {

            public function __construct(private readonly Closure $advance) {}

            public function wait(float $seconds): void
            {
                ($this->advance)($seconds);
            }
        };
        $handle = new class implements ProcessHandleInterface {
            public array $signals = [];
            public bool $quiet = false;

            public function isRunning(): bool
            {
                return !$this->quiet;
            }

            public function isQuiescent(): bool
            {
                return $this->quiet;
            }

            public function poll(): ?ProcessResult
            {
                return $this->quiet ? new ProcessResult(0) : null;
            }

            public function terminateSoft(): void
            {
                $this->signals[] = 'soft';
            }

            public function terminateHard(): void
            {
                $this->signals[] = 'hard';
                $this->quiet = true;
            }
        };
        $supervisor = new ParentLifecycleSupervisor($clock, $waiter);
        $launch = $supervisor->retain(new ExecutionWorkerLaunchRequest($this->capability(), new WorkerLifecyclePolicy(0.1, 0.1), 0), $handle);
        $clock->time = 1;

        self::assertTrue($supervisor->shutdown(1));
        self::assertSame(['soft', 'hard'], $handle->signals);
        $observations = $supervisor->takeShutdownObservations();
        self::assertCount(1, $observations);
        self::assertTrue($observations[0]->closure?->isConfirmed());
        self::assertSame($launch->value, $observations[0]->launchHandle?->value);
        self::assertSame([], $supervisor->takeShutdownObservations());
    }

    public function testShutdownRetainsPollFailureObservationsAndLaunchCorrelation(): void
    {
        $clock = new class implements MonotonicClockInterface {
            public function now(): float
            {
                return 0;
            }
        };
        $waiter = new class implements PollWaiterInterface {
            public function wait(float $seconds): void {}
        };
        $handle = new class implements ProcessHandleInterface {
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
                throw new RuntimeException('poll failed');
            }

            public function terminateSoft(): void {}

            public function terminateHard(): void {}
        };
        $supervisor = new ParentLifecycleSupervisor($clock, $waiter);
        $launch = $supervisor->retain(new ExecutionWorkerLaunchRequest($this->capability(), new WorkerLifecyclePolicy(), 0), $handle);

        self::assertFalse($supervisor->shutdown(0));
        $observations = $supervisor->takeShutdownObservations();
        self::assertCount(2, $observations);
        foreach ($observations as $observation) {
            self::assertSame('poll-failed', $observation->classification);
            self::assertSame($launch->value, $observation->launchHandle?->value);
            self::assertFalse($observation->closure?->isConfirmed());
        }
        self::assertTrue($supervisor->hasUnresolvedWorkers());
    }

    public function testShutdownMarksSoftSignalOnceWhenItThrows(): void
    {
        $clock = new class implements MonotonicClockInterface {
            public function now(): float
            {
                return 0;
            }
        };
        $waiter = new class implements PollWaiterInterface {
            public function wait(float $seconds): void {}
        };
        $handle = new class implements ProcessHandleInterface {
            public int $soft = 0;

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
                return null;
            }

            public function terminateSoft(): void
            {
                $this->soft++;
                throw new RuntimeException('signal failed');
            }

            public function terminateHard(): void {}
        };
        $supervisor = new ParentLifecycleSupervisor($clock, $waiter);
        $supervisor->retain(new ExecutionWorkerLaunchRequest($this->capability(), new WorkerLifecyclePolicy(), 0), $handle);

        self::assertFalse($supervisor->shutdown(0));
        self::assertSame(1, $handle->soft);
        self::assertFalse($supervisor->shutdown(0));
        self::assertSame(1, $handle->soft);
    }

    public function testTerminalPollIsNotInvalidatedByASecondProcessTableObservation(): void
    {
        $clock = new class implements MonotonicClockInterface {
            public function now(): float
            {
                return 0;
            }
        };
        $waiter = new class implements PollWaiterInterface {
            public function wait(float $seconds): void {}
        };
        $handle = new class implements ProcessHandleInterface {
            private bool $polled = false;

            public function isRunning(): bool
            {
                return false;
            }

            public function isQuiescent(): bool
            {
                if ($this->polled) {
                    throw new RuntimeException('process table changed after reap');
                }
                return true;
            }

            public function poll(): ?ProcessResult
            {
                $this->polled = true;
                return new ProcessResult(0);
            }

            public function terminateSoft(): void {}

            public function terminateHard(): void {}
        };
        $supervisor = new ParentLifecycleSupervisor($clock, $waiter);
        $supervisor->retain(new ExecutionWorkerLaunchRequest($this->capability(), new WorkerLifecyclePolicy(), 0), $handle);

        $observations = $supervisor->poll();
        self::assertCount(1, $observations);
        self::assertTrue($observations[0]->closure?->isConfirmed());
        self::assertFalse($supervisor->hasUnresolvedWorkers());
    }

    private function capability(): ExecutionWorkerCapability
    {
        return new ExecutionWorkerCapability(
            new ExecutionOccurrenceKey('t', 'o'),
            'a',
            'r',
            'e',
            1,
            'fence',
            'manifest',
            'version',
            'ledger',
            'runtime',
            'source-revision',
            [
                'format' => 4,
                'source' => ['source', 'source-revision'],
                'task' => [
                    'id' => 't', 'mode' => 'async', 'timeout' => null, 'metadata' => [],
                    'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]],
                    'overlap' => 'allow', 'missed' => ['skip', null],
                ],
            ],
        );
    }
}
