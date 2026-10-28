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
use Yiisoft\Scheduler\Integration\Async\Process\{ExecutionWorkerProcessCommandBuilderInterface, ExecutionWorkerProcessHandleObserverInterface, LaunchDescriptorStoreInterface, ProcessIdentity, ProcessScopeException, ProcessScopeProof, SymfonyExecutionWorkerLauncher, SymfonyProcessHandle, SymfonyProcessLauncher};
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchDescriptor;

use function constant;
use function defined;
use function getenv;
use function putenv;
use function strlen;
use function var_export;

use const PHP_BINARY;

final class SymfonyExecutionWorkerLauncherTest extends TestCase
{
    public function testForwardsOpaqueRequestAndApplicationBuiltProcessInOrder(): void
    {
        $events = [];
        $request = $this->request();
        $processRequest = new ProcessLaunchRequest('application-worker', ['--private-input'], null, ['APP_MODE' => 'test'], 'opaque input', $request->descriptor);
        $handle = new FakeProcessHandle();
        $launchResult = new ProcessLaunchResult($handle, 1.0);
        $returned = new ExecutionWorkerLaunchHandle('application-handle');
        $builder = new FakeBuilder($processRequest, $events);
        $launcher = new FakeLauncher($processRequest, $launchResult, $events);
        $observer = new FakeObserver($request, $launchResult, $returned, $events);
        $descriptorStore = new FakeDescriptorStore($events);

        $actual = (new SymfonyExecutionWorkerLauncher($builder, $observer, $launcher, $descriptorStore))->launch($request);

        self::assertSame($returned, $actual->handle);
        self::assertSame($launchResult, $actual->processLaunchResult);
        self::assertSame(['descriptor', 'build', 'launch', 'retain'], $events);
    }

    public function testFinalAdapterBoundaryMasksInheritedMarkersForWorkersAndProbes(): void
    {
        $workerMarker = ExecutionLaunchDescriptor::WORKER_MARKER;
        $commandMarker = ExecutionLaunchDescriptor::COMMAND_MARKER;
        $inheritedWorkerMarker = 'inherited-worker-marker';
        $inheritedCommandMarker = 'inherited-command-marker';
        $previousWorkerMarker = getenv($workerMarker);
        $previousCommandMarker = getenv($commandMarker);
        putenv($workerMarker . '=' . $inheritedWorkerMarker);
        putenv($commandMarker . '=' . $inheritedCommandMarker);

        $probe = 'echo json_encode([getenv(' . var_export($workerMarker, true) . '), getenv(' . var_export($commandMarker, true) . ')]);';
        $descriptor = new ExecutionLaunchDescriptor('boundary-worker', 1, 'worker-nonce');

        try {
            $workerResult = $this->collectProcessResult(new ProcessLaunchRequest(
                PHP_BINARY,
                ['-r', $probe],
                null,
                [],
                '',
                $descriptor,
            ));
            self::assertSame('["' . $descriptor->marker() . '",false]', $workerResult->stdout);

            $probeResult = $this->collectProcessResult(new ProcessLaunchRequest(
                PHP_BINARY,
                ['-r', $probe],
                null,
                [],
                '',
            ));
            self::assertSame('[false,false]', $probeResult->stdout);
        } finally {
            putenv($previousWorkerMarker === false ? $workerMarker : $workerMarker . '=' . $previousWorkerMarker);
            putenv($previousCommandMarker === false ? $commandMarker : $commandMarker . '=' . $previousCommandMarker);
        }
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
        self::assertGreaterThanOrEqual(0.0, $launchResult->startedAt);
        self::assertNotNull($launchResult->wallStartedAtMicros);

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
        $handle = (new SymfonyProcessLauncher())->launch(new ProcessLaunchRequest(
            PHP_BINARY,
            ['-r', 'while (true) {}'],
            null,
            [],
            '',
        ))->handle;
        $handle->terminateHard();

        $result = null;
        for ($polls = 0; $polls < 10000 && $result === null; ++$polls) {
            $result = $handle->poll();
        }

        self::assertNotNull($result);
        self::assertSame(defined('SIGKILL') ? (int) constant('SIGKILL') : 9, $result->signal);
    }

    public function testUnboundMockCannotSignalEvenWhenSymfonyReportsRunning(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(true);
        $process->expects(self::never())->method('signal');

        $handle = new SymfonyProcessHandle($process);
        try {
            $handle->terminateSoft();
            self::fail('Expected an unbound scope exception.');
        } catch (ProcessScopeException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(ProcessScopeException::class);
        $handle->terminateHard();
    }

    public function testBuilderFailureDoesNotStartOrObserve(): void
    {
        $events = [];
        $builder = new FakeBuilder(null, $events, new RuntimeException('capability fence secret'));
        $launcher = new FakeLauncher(null, new ProcessLaunchResult(new FakeProcessHandle(), 1.0), $events);
        $observer = new FakeObserver($this->request(), new ProcessLaunchResult(new FakeProcessHandle(), 1.0), new ExecutionWorkerLaunchHandle('h'), $events);

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

    public function testDescriptorMismatchIsBuilderFailureBeforeExternalStart(): void
    {
        foreach ([null, new ExecutionLaunchDescriptor('other', 1, 'other-nonce')] as $descriptor) {
            $events = [];
            $request = $this->request();
            $processRequest = new ProcessLaunchRequest('worker', ['x'], null, [], 'input', $descriptor);
            $launcher = new FakeLauncher($processRequest, new ProcessLaunchResult(new FakeProcessHandle(), 1.0), $events);

            try {
                (new SymfonyExecutionWorkerLauncher(new FakeBuilder($processRequest, $events), new FakeObserver($request, new ProcessLaunchResult(new FakeProcessHandle(), 1.0), new ExecutionWorkerLaunchHandle('h'), $events), $launcher))->launch($request);
                self::fail('Expected descriptor validation failure.');
            } catch (ExecutionWorkerLaunchException $e) {
                self::assertSame(ExecutionWorkerLaunchException::PHASE_BUILD, $e->phase);
                self::assertSame(['build'], $events);
            }
        }
    }

    public function testStartAndObserverFailuresAreAmbiguousWithoutRetry(): void
    {
        $events = [];
        $request = $this->request();
        $processRequest = new ProcessLaunchRequest('worker', ['x'], null, [], 'input', $request->descriptor);
        $launcher = new FakeLauncher($processRequest, new ProcessLaunchResult(new FakeProcessHandle(), 1.0), $events, new RuntimeException('fence raw'));
        $observer = new FakeObserver($request, new ProcessLaunchResult(new FakeProcessHandle(), 1.0), new ExecutionWorkerLaunchHandle('h'), $events);
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
        $observer = new FakeObserver($request, new ProcessLaunchResult(new FakeProcessHandle(), 1.0), new ExecutionWorkerLaunchHandle('h'), $events, new RuntimeException('secret'));
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

    private function collectProcessResult(ProcessLaunchRequest $request): ProcessResult
    {
        $result = (new SymfonyProcessLauncher())->launch($request)->handle;
        $completion = null;
        for ($polls = 0; $polls < 10000 && $completion === null; ++$polls) {
            $completion = $result->poll();
        }

        self::assertNotNull($completion);
        self::assertSame(0, $completion->exitCode);

        return $completion;
    }

    private function request(): ExecutionWorkerLaunchRequest
    {
        $policy = ['schema' => 'dev', 'source' => ['source', 'source-revision'], 'task' => ['id' => 'task', 'mode' => 'async', 'timeout' => null, 'recoveryMaxAge' => 180.0, 'metadata' => [], 'trigger' => ['periodic', ['type' => 'periodic', 'seconds' => 60, 'from' => null, 'until' => null]], 'overlap' => 'allow', 'missed' => ['skip', null]]];
        $occurrence = new ExecutionOccurrenceKey('task', 'occurrence');
        $descriptor = new ExecutionLaunchDescriptor('execution-1', 1, 'fixture-worker', occurrence: $occurrence);
        return new ExecutionWorkerLaunchRequest(new ExecutionWorkerCapability(
            $occurrence,
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
            launchDescriptor: $descriptor,
            permissionId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ), descriptor: $descriptor);
    }
}

final class FakeDescriptorStore implements LaunchDescriptorStoreInterface
{
    public function __construct(private array &$events) {}

    public function record(ExecutionWorkerLaunchRequest $request, ExecutionLaunchDescriptor $descriptor): void
    {
        $this->events[] = 'descriptor';
        TestCase::assertSame($request->descriptor, $descriptor);
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
    public function __construct(private readonly ExecutionWorkerLaunchRequest $expectedRequest, private readonly ProcessLaunchResult $expectedLaunch, private readonly ExecutionWorkerLaunchHandle $result, private array &$events, private readonly ?Throwable $failure = null) {}

    public function retain(ExecutionWorkerLaunchRequest $request, ProcessLaunchResult $launch): ExecutionWorkerLaunchHandle
    {
        $this->events[] = 'retain';
        TestCase::assertSame($this->expectedRequest, $request);
        if ($this->failure !== null) {
            throw $this->failure;
        }
        TestCase::assertSame($this->expectedLaunch, $launch);
        return $this->result;
    }
}

final class FakeProcessHandle implements ProcessHandleInterface
{
    public function scopeProof(): ProcessScopeProof
    {
        $descriptor = new ExecutionLaunchDescriptor('test', 1, 'test');
        return ProcessScopeProof::closedTerminalFromRetainedPoll($descriptor, new ProcessIdentity(10, 10, 10, '1', 'boot', $descriptor));
    }

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
