<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Yiisoft\Scheduler\Config\PreparedConfiguration;
use Symfony\Component\Process\Process;
use Yiisoft\Scheduler\Executor\ShellCommandExecutor;
use Yiisoft\Scheduler\Integration\Async\{ExecutionOccurrenceKey,ExecutionWorkerCapability,ExecutionWorkerLaunchRequest,UsleepPollWaiter};
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessLaunchRequest,ProcessResult,SymfonyExecutionWorkerLauncher,SymfonyProcessLauncher};
use Yiisoft\Scheduler\Integration\Async\Store\FileExecutionLedger;
use Yiisoft\Scheduler\Tests\Support\{BlackBoxApplication,BlackBoxObserver,BlackBoxShellCommandBuilder};

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_executable;
use function json_decode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function strlen;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

final class BlackBoxShellProcessTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!class_exists(Process::class) || !is_executable(PHP_BINARY)) {
            self::markTestSkipped('PHP subprocess or Symfony Process is unavailable.');
        }
        $this->directory = sys_get_temp_dir() . '/yii-schedule-shell-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/ledger', 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $path) {
                is_dir($path) ? $this->remove($path) : unlink($path);
            }
            rmdir($this->directory);
        }
    }

    public function testShellUsesOneTypedExecutorRuntimeFactoryBoundary(): void
    {
        $executor = new ReflectionClass(ShellCommandExecutor::class);
        self::assertTrue($executor->implementsInterface('Yiisoft\\Scheduler\\Executor\\ExecutorInterface'));

        $factory = new ReflectionClass('Yiisoft\\Scheduler\\Integration\\Async\\ShellCommandRuntimeFactoryInterface');
        self::assertTrue($factory->isInterface());
        $constructor = $executor->getConstructor();
        self::assertNotNull($constructor);
        self::assertCount(1, $constructor->getParameters());
        self::assertSame(
            'Yiisoft\\Scheduler\\Integration\\Async\\ShellCommandRuntimeFactoryInterface',
            (string) $constructor->getParameters()[0]->getType(),
        );
    }

    public function testAcceptedClaimBootstrapsBeforeLiteralChildAndPersistsSafeOutcome(): void
    {
        [$ledger, $capability, $config, $marker, $effect] = $this->fixture('success');
        $config['argv'] = [$this->child(), $effect, 'success', '${VALUE}', 'with spaces', '', '-leading', 'a; touch ' . $this->directory . '/injected'];
        $config['variables'] = ['VALUE' => 'config-value'];
        $config['overrides'] = ['VALUE' => 'black-box-runtime-override-secret'];
        $config['environment'] += ['FIXTURE_ENV' => 'environment-value', 'FIXTURE_SECRET' => 'child-secret', 'BOOTSTRAP_MARKER' => $marker];
        $this->writeConfig($config);

        $result = $this->executeWorker($capability, $config['path']);
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertFileExists($marker);
        $payload = json_decode((string) file_get_contents($effect), true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['markerSeen']);
        self::assertSame(array_map(static fn(string $value): string => hash('sha256', $value), ['black-box-runtime-override-secret', 'with spaces', '', '-leading', 'a; touch ' . $this->directory . '/injected']), $payload['argv']);
        self::assertSame('environment-value', $payload['environment']);
        self::assertTrue($payload['secretPresent']);
        self::assertFileDoesNotExist($this->directory . '/injected');
        self::assertSame('succeeded', $ledger->observe($capability->occurrence)?->outcome?->status);
        $diagnostics = json_encode($ledger->observe($capability->occurrence), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('black-box-child-secret', $result->stdout . $result->stderr . $diagnostics);
        self::assertStringNotContainsString('black-box-runtime-override-secret', $result->stdout . $result->stderr . $diagnostics);
        self::assertStringNotContainsString('override-secret', $diagnostics);
        self::assertStringNotContainsString('child-secret', $diagnostics);
        self::assertStringNotContainsString('environment-value', $diagnostics);
        $encodedCapability = $capability->encode();
        $diagnosticCapability = json_encode($capability->diagnostic(), JSON_THROW_ON_ERROR);
        self::assertStringContainsString(hash('sha256', PreparedConfiguration::canonical($capability->policy)), $encodedCapability);
        self::assertSame($encodedCapability, ExecutionWorkerCapability::decode($encodedCapability)->encode());
        foreach ([PHP_BINARY, $this->child(), $effect, $marker, 'success', 'with spaces', 'command-value', 'runtime-value', 'environment-value', 'FIXTURE_ENV', 'FIXTURE_SECRET', 'BOOTSTRAP_MARKER', 'child-secret', 'override-secret', 'black-box-child-secret', 'black-box-runtime-override-secret'] as $unsafeValue) {
            self::assertStringNotContainsString($unsafeValue, $encodedCapability);
            self::assertStringNotContainsString($unsafeValue, $diagnosticCapability);
        }
        foreach (['black-box-child-secret', 'black-box-runtime-override-secret', 'override-secret', 'child-secret'] as $secret) {
            self::assertStringNotContainsString($secret, (string) file_get_contents($effect));
        }
    }

    public function testEnvironmentOverridesCommandVariablesInChildArguments(): void
    {
        [$ledger, $capability, $config, , $effect] = $this->fixture('success');
        $config['argv'] = [$this->child(), $effect, 'success', '${VALUE}'];
        $config['variables'] = ['VALUE' => 'command-value'];
        $config['environment'] = ['VALUE' => 'environment-value'];
        $this->writeConfig($config);

        $result = $this->executeWorker($capability, $config['path']);
        self::assertSame(0, $result->exitCode, $result->stderr);
        $payload = json_decode((string) file_get_contents($effect), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame([hash('sha256', 'environment-value')], $payload['argv']);
        self::assertSame('succeeded', $ledger->observe($capability->occurrence)?->outcome?->status);
    }

    public function testRuntimeOverridesEnvironmentInChildArguments(): void
    {
        [$ledger, $capability, $config, , $effect] = $this->fixture('success');
        $config['argv'] = [$this->child(), $effect, 'success', '${VALUE}'];
        $config['variables'] = ['VALUE' => 'command-value'];
        $config['environment'] = ['VALUE' => 'environment-value'];
        $config['overrides'] = ['VALUE' => 'runtime-value'];
        $this->writeConfig($config);

        $result = $this->executeWorker($capability, $config['path']);
        self::assertSame(0, $result->exitCode, $result->stderr);
        $payload = json_decode((string) file_get_contents($effect), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame([hash('sha256', 'runtime-value')], $payload['argv']);
        self::assertSame('succeeded', $ledger->observe($capability->occurrence)?->outcome?->status);
    }

    public function testLaunchFailureDoesNotRunChildOrFallbackSynchronously(): void
    {
        [$ledger, $capability, $config, $marker, $effect] = $this->fixture('success');
        $config['cwd'] = $this->directory . '/missing-cwd';
        $this->writeConfig($config);

        $result = $this->executeWorker($capability, $config['path']);
        self::assertNotSame(0, $result->exitCode);
        self::assertFileExists($marker);
        self::assertFileDoesNotExist($effect);
        self::assertSame('failed', $ledger->observe($capability->occurrence)?->outcome?->status);
        self::assertSame('process execution failed', $ledger->observe($capability->occurrence)?->outcome?->reason);
    }

    public function testStaleCapabilityNeverBootstrapsOrLaunchesChild(): void
    {
        [$ledger, $capability, $config, $marker, $effect] = $this->fixture('success');
        $stale = BlackBoxApplication::capability($capability->occurrence, 'stale-fence');
        $result = $this->executeWorker($stale, $config['path']);
        self::assertNotSame(0, $result->exitCode);
        self::assertFileDoesNotExist($marker);
        self::assertFileDoesNotExist($effect);
        self::assertNull($ledger->observe($capability->occurrence)?->outcome);
    }

    public function testHardTimeoutTerminatesChildAndPersistsBoundedFailure(): void
    {
        [$ledger, $capability, $config, $marker, $effect] = $this->fixture('timeout');
        $config['argv'] = [$this->child(), $effect, 'timeout'];
        $config['hard'] = 0.08;
        $this->writeConfig($config);
        $result = $this->executeWorker($capability, $config['path']);
        self::assertNotSame(0, $result->exitCode);
        self::assertFileExists($marker);
        self::assertSame('failed', $ledger->observe($capability->occurrence)?->outcome?->status);
        self::assertSame('hard', $ledger->observe($capability->occurrence)?->outcome?->metadata['timeout']);
        self::assertSame("started\n", file_get_contents($effect));
    }

    public function testLargeProcessOutputIsBoundedWithoutChangingExitLifecycle(): void
    {
        $length = ProcessResult::MAX_DIAGNOSTIC_LENGTH + 1;
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
        self::assertSame(0, $result->exitCode);
        self::assertFalse($handle->isRunning());
        self::assertSame(ProcessResult::MAX_DIAGNOSTIC_LENGTH, strlen($result->stdout));
        self::assertSame(ProcessResult::MAX_DIAGNOSTIC_LENGTH, strlen($result->stderr));
        self::assertSame(str_repeat('o', ProcessResult::MAX_DIAGNOSTIC_LENGTH), $result->stdout);
        self::assertSame(str_repeat('e', ProcessResult::MAX_DIAGNOSTIC_LENGTH), $result->stderr);
        self::assertTrue($result->stdoutTruncated);
        self::assertTrue($result->stderrTruncated);
        self::assertSame($result, $handle->poll());
    }

    public function testNonzeroChildExitIsFailedWithoutChildOutputInOutcome(): void
    {
        [$ledger, $capability, $config, , $effect] = $this->fixture('nonzero');
        $config['argv'] = [$this->child(), $effect, 'nonzero'];
        $this->writeConfig($config);
        $result = $this->executeWorker($capability, $config['path']);
        self::assertNotSame(0, $result->exitCode);
        self::assertStringContainsString('committed-failed', $result->stderr);
        self::assertSame('failed', $ledger->observe($capability->occurrence)?->outcome?->status);
        self::assertSame(7, $ledger->observe($capability->occurrence)?->outcome?->metadata['exitCode']);
        $ledgerData = json_encode($ledger->observe($capability->occurrence), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('black-box-child-secret', $result->stdout . $result->stderr . $ledgerData);
        self::assertStringNotContainsString('black-box-runtime-override-secret', (string) file_get_contents($effect));
        self::assertStringNotContainsString('black-box-runtime-override-secret', $result->stdout . $result->stderr . $ledgerData);
        self::assertStringNotContainsString('child-secret', $ledgerData);
        self::assertStringNotContainsString('black-box-child-secret', (string) file_get_contents($effect));
    }

    private function fixture(string $mode): array
    {
        $occurrence = new ExecutionOccurrenceKey('fixture-task', 'shell-' . bin2hex(random_bytes(4)));
        $capability = BlackBoxApplication::capability($occurrence);
        $capability = new ExecutionWorkerCapability($capability->occurrence, $capability->attemptId, $capability->runId, $capability->executionId, $capability->generation, $capability->fenceToken, $capability->configurationFingerprint, hash('sha256', PreparedConfiguration::canonical($capability->policy)), $capability->ledgerLocator, $capability->runtimeLocator, $capability->sourceRevision, $capability->policy);
        $ledger = new FileExecutionLedger($this->directory . '/ledger');
        $ledger->admit(BlackBoxApplication::record($capability));
        $marker = $this->directory . '/marker';
        $effect = $this->directory . '/effect';
        $path = $this->directory . '/config.json';
        $config = ['path' => $path, 'marker' => $marker, 'executable' => PHP_BINARY, 'argv' => [$this->child(), $effect, $mode], 'variables' => [], 'overrides' => [], 'environment' => []];
        $this->writeConfig($config);
        return [$ledger, $capability, $config, $marker, $effect];
    }

    private function writeConfig(array $config): void
    {
        file_put_contents($config['path'], json_encode($config, JSON_THROW_ON_ERROR));
    }

    private function child(): string
    {
        return __DIR__ . '/Process/shell-child.php';
    }

    private function executeWorker(ExecutionWorkerCapability $capability, string $config): ProcessResult
    {
        $observer = new BlackBoxObserver();
        (new SymfonyExecutionWorkerLauncher(new BlackBoxShellCommandBuilder(__DIR__ . '/Process/black-box-shell-worker.php', $this->directory . '/ledger', $config), $observer))->launch(new ExecutionWorkerLaunchRequest($capability));
        $waiter = new UsleepPollWaiter();
        for ($polls = 0; $polls < 10000; ++$polls) {
            if (($result = $observer->handle?->poll()) !== null) {
                return $result;
            }
            $waiter->wait(0.001);
        }
        $observer->handle?->terminateHard();
        self::fail('shell worker exceeded bounded observation polls');
    }

    private function remove(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $path) {
            is_dir($path) ? $this->remove($path) : unlink($path);
        } rmdir($directory);
    }
}
