<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Process;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Yiisoft\Scheduler\Config\PreparedConfiguration;
use Throwable;
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessHandleInterface, ProcessLaunchRequest, ProcessLaunchResult, ProcessLauncherInterface, ProcessResult};
use Yiisoft\Scheduler\Integration\Async\{ExecutionOccurrenceKey, ExecutionWorkerCapability, ExecutionWorkerLaunchException, ExecutionWorkerLaunchHandle, ExecutionWorkerLaunchRequest};
use Yiisoft\Scheduler\Integration\Async\Process\{ExecutionWorkerProcessCommandBuilderInterface, ExecutionWorkerProcessHandleObserverInterface, SymfonyExecutionWorkerLauncher, SymfonyProcessHandle, SymfonyProcessLauncher};

use function constant;
use function defined;
use function strlen;

use const PHP_BINARY;

final class SymfonyExecutionWorkerLauncherTest extends TestCase
{
    public function testForwardsOpaqueRequestAndApplicationBuiltProcessInOrder(): void
    {
        $events = [];
        $request = $this->request();
        $processRequest = new ProcessLaunchRequest('application-worker', ['--private-input'], null, ['APP_MODE' => 'test'], 'opaque input');
        $handle = new FakeProcessHandle();
        $launchResult = new ProcessLaunchResult($handle, 1.0);
        $returned = new ExecutionWorkerLaunchHandle('application-handle');
        $builder = new FakeBuilder($processRequest, $events);
        $launcher = new FakeLauncher($processRequest, $launchResult, $events);
        $observer = new FakeObserver($request, $handle, $returned, $events);

        $actual = (new SymfonyExecutionWorkerLauncher($builder, $observer, $launcher))->launch($request);

        self::assertSame($returned, $actual);
        self::assertSame(['build', 'launch', 'retain'], $events);
    }

    public function testSuccessfulProcessHasExitCodeAndNoTerminationSignal(): void
    {
        $launchResult = (new SymfonyProcessLauncher())->launch(new ProcessLaunchRequest(
            PHP_BINARY,
            ['-r', 'echo "stdout"; fwrite(STDERR, "stderr");'],
            null,
            [],
            '',
        ));
        $handle = $launchResult->handle;

        $result = null;
        for ($polls = 0; $polls < 10000 && $result === null; ++$polls) {
            $result = $handle->poll();
        }

        self::assertNotNull($result);
        self::assertSame(0, $result->exitCode);
        self::assertNull($result->signal);
        self::assertSame('stdout', $result->stdout);
        self::assertSame('stderr', $result->stderr);
    }

    public function testOutputIsCollectedIncrementallyAndRetainedDiagnosticsAreBounded(): void
    {
        $launchResult = (new SymfonyProcessLauncher())->launch(new ProcessLaunchRequest(
            PHP_BINARY,
            ['-r', 'echo str_repeat("o", 200000); fwrite(STDERR, str_repeat("e", 200000));'],
            null,
            [],
            '',
        ));
        $handle = $launchResult->handle;

        $firstPoll = $handle->poll();
        self::assertNull($firstPoll);
        $result = $firstPoll;
        for ($polls = 1; $polls < 10000 && $result === null; ++$polls) {
            $result = $handle->poll();
        }
        self::assertNotNull($result);
        $cached = $handle->poll();

        self::assertNotNull($result);
        self::assertSame(0, $result->exitCode);
        self::assertSame(ProcessResult::MAX_DIAGNOSTIC_LENGTH, strlen($result->stdout));
        self::assertSame(ProcessResult::MAX_DIAGNOSTIC_LENGTH, strlen($result->stderr));
        self::assertSame(str_repeat('o', ProcessResult::MAX_DIAGNOSTIC_LENGTH), $result->stdout);
        self::assertSame(str_repeat('e', ProcessResult::MAX_DIAGNOSTIC_LENGTH), $result->stderr);
        self::assertTrue($result->stdoutTruncated);
        self::assertTrue($result->stderrTruncated);
        self::assertSame($result, $cached);
    }

    public function testOutputExactlyAtDiagnosticBoundIsNotMarkedTruncated(): void
    {
        $length = ProcessResult::MAX_DIAGNOSTIC_LENGTH;
        $launchResult = (new SymfonyProcessLauncher())->launch(new ProcessLaunchRequest(
            PHP_BINARY,
            ['-r', 'echo str_repeat("o", ' . $length . '); fwrite(STDERR, str_repeat("e", ' . $length . '));'],
            null,
            [],
            '',
        ));
        $handle = $launchResult->handle;

        $result = null;
        for ($polls = 0; $polls < 10000 && $result === null; ++$polls) {
            $result = $handle->poll();
        }

        self::assertNotNull($result);
        self::assertSame($length, strlen($result->stdout));
        self::assertSame($length, strlen($result->stderr));
        self::assertFalse($result->stdoutTruncated);
        self::assertFalse($result->stderrTruncated);
    }

    public function testSignaledProcessRetainsTerminationSignal(): void
    {
        $process = new Process([PHP_BINARY, '-r', 'while (true) {}']);
        $process->start();
        $handle = new SymfonyProcessHandle($process);
        $handle->terminateHard();

        $result = null;
        for ($polls = 0; $polls < 10000 && $result === null; ++$polls) {
            $result = $handle->poll();
        }

        self::assertNotNull($result);
        self::assertSame(defined('SIGKILL') ? (int) constant('SIGKILL') : 9, $result->signal);
    }

    public function testTerminationUsesPortableSignalNumbersWhenPcntlConstantsAreUnavailable(): void
    {
        class_exists(SymfonyExecutionWorkerLauncher::class);
        $process = $this->createMock(Process::class);
        $process->expects(self::exactly(2))->method('isRunning')->willReturn(true);
        $signals = [];
        $process->expects(self::exactly(2))->method('signal')->willReturnCallback(
            static function (int $signal) use (&$signals, $process): Process {
                $signals[] = $signal;
                return $process;
            },
        );

        $handle = new SymfonyProcessHandle($process);
        $handle->terminateSoft();
        $handle->terminateHard();

        self::assertSame([
            defined('SIGTERM') ? (int) constant('SIGTERM') : 15,
            defined('SIGKILL') ? (int) constant('SIGKILL') : 9,
        ], $signals);
    }

    public function testBuilderFailureDoesNotStartOrObserve(): void
    {
        $events = [];
        $builder = new FakeBuilder(null, $events, new RuntimeException('capability fence secret'));
        $launcher = new FakeLauncher(null, new ProcessLaunchResult(new FakeProcessHandle(), 1.0), $events);
        $observer = new FakeObserver($this->request(), new FakeProcessHandle(), new ExecutionWorkerLaunchHandle('h'), $events);

        try {
            (new SymfonyExecutionWorkerLauncher($builder, $observer, $launcher))->launch($this->request());
            self::fail('Expected exception.');
        } catch (ExecutionWorkerLaunchException $e) {
            self::assertSame(ExecutionWorkerLaunchException::PHASE_BUILD, $e->phase);
            self::assertSame(ExecutionWorkerLaunchException::STATUS_NOT_STARTED, $e->status);
            self::assertSame(['build'], $events);
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('capability', strtolower($e->getMessage()));
        }
    }

    public function testStartAndObserverFailuresAreAmbiguousWithoutRetry(): void
    {
        $events = [];
        $request = $this->request();
        $processRequest = new ProcessLaunchRequest('worker', ['x'], null, [], 'input');
        $launcher = new FakeLauncher($processRequest, new ProcessLaunchResult(new FakeProcessHandle(), 1.0), $events, new RuntimeException('fence raw'));
        $observer = new FakeObserver($request, new FakeProcessHandle(), new ExecutionWorkerLaunchHandle('h'), $events);
        try {
            (new SymfonyExecutionWorkerLauncher(new FakeBuilder($processRequest, $events), $observer, $launcher))->launch($request);
            self::fail('Expected exception.');
        } catch (ExecutionWorkerLaunchException $e) {
            self::assertSame(ExecutionWorkerLaunchException::PHASE_START, $e->phase);
            self::assertSame(ExecutionWorkerLaunchException::STATUS_AMBIGUOUS, $e->status);
            self::assertSame(['build', 'launch'], $events);
            self::assertStringNotContainsString('fence', strtolower($e->getMessage()));
        }

        $events = [];
        $observer = new FakeObserver($request, new FakeProcessHandle(), new ExecutionWorkerLaunchHandle('h'), $events, new RuntimeException('secret'));
        $launcher = new FakeLauncher($processRequest, new ProcessLaunchResult(new FakeProcessHandle(), 1.0), $events);
        try {
            (new SymfonyExecutionWorkerLauncher(new FakeBuilder($processRequest, $events), $observer, $launcher))->launch($request);
            self::fail('Expected exception.');
        } catch (ExecutionWorkerLaunchException $e) {
            self::assertSame(ExecutionWorkerLaunchException::PHASE_OBSERVER, $e->phase);
            self::assertSame(['build', 'launch', 'retain'], $events);
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    private function request(): ExecutionWorkerLaunchRequest
    {
        $policy = ['format' => 4, 'source' => [null, null], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => null, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]];
        return new ExecutionWorkerLaunchRequest(new ExecutionWorkerCapability(
            new ExecutionOccurrenceKey('task', 'occurrence'),
            'attempt',
            'run-1',
            'execution-1',
            1,
            'fence-secret',
            'manifest',
            hash('sha256', PreparedConfiguration::canonical($policy)),
            'ledger',
            'runtime',
            'source-revision',
            $policy,
        ));
    }
}

final class FakeBuilder implements ExecutionWorkerProcessCommandBuilderInterface
{
    public function __construct(private readonly ?ProcessLaunchRequest $result, private array &$events, private readonly ?Throwable $failure = null) {}

    public function build(ExecutionWorkerLaunchRequest $request): ProcessLaunchRequest
    {
        $this->events[] = 'build';
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->result;
    }
}

final class FakeLauncher implements ProcessLauncherInterface
{
    public function __construct(private readonly ?ProcessLaunchRequest $expected, private readonly ProcessLaunchResult $result, private array &$events, private readonly ?Throwable $failure = null) {}

    public function launch(ProcessLaunchRequest $request): ProcessLaunchResult
    {
        $this->events[] = 'launch';
        if ($this->failure !== null) {
            throw $this->failure;
        }
        TestCase::assertSame($this->expected, $request);
        return $this->result;
    }
}

final class FakeObserver implements ExecutionWorkerProcessHandleObserverInterface
{
    public function __construct(private readonly ExecutionWorkerLaunchRequest $expectedRequest, private readonly ProcessHandleInterface $expectedHandle, private readonly ExecutionWorkerLaunchHandle $result, private array &$events, private readonly ?Throwable $failure = null) {}

    public function retain(ExecutionWorkerLaunchRequest $request, ProcessHandleInterface $handle): ExecutionWorkerLaunchHandle
    {
        $this->events[] = 'retain';
        TestCase::assertSame($this->expectedRequest, $request);
        if ($this->failure !== null) {
            throw $this->failure;
        }
        TestCase::assertSame($this->expectedHandle, $handle);
        return $this->result;
    }
}

final class FakeProcessHandle implements ProcessHandleInterface
{
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

    public function terminateSoft(): void {}

    public function terminateHard(): void {}
}
