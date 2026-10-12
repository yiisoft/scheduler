<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Integration\Async;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Yiisoft\Schedule\Config\PreparedConfiguration;
use Throwable;
use Yiisoft\Schedule\Integration\Async\{ExecutionDispatchReservation,ExecutionFence,ExecutionIntegrationCoordinator,ExecutionLaunchObservation,ExecutionLedgerInterface,ExecutionLedgerState,ExecutionOccurrenceKey,ExecutionWorkerCapability,ExecutionWorkerLaunchRequest};
use Yiisoft\Schedule\Integration\Async\Process\{ProcessResult,SymfonyExecutionWorkerLauncher};
use Yiisoft\Schedule\Integration\Async\Store\FileExecutionLedger;
use Yiisoft\Schedule\Tests\Support\{BlackBoxApplication,BlackBoxApplicationLauncher,BlackBoxApplicationSource,BlackBoxCommandBuilder,BlackBoxObserver};

use function file_get_contents;
use function is_dir;
use function is_executable;
use function mkdir;
use function microtime;
use function rmdir;
use function strlen;
use function sys_get_temp_dir;
use function unlink;

use const PHP_BINARY;

final class BlackBoxProcessTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(Process::class) || !is_executable(PHP_BINARY)) {
            self::markTestSkipped('PHP subprocess or Symfony Process is unavailable; acceptance result is unknown.');
        }
        $this->directory = sys_get_temp_dir() . '/yii-schedule-black-box-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            $this->removeDirectory($this->directory);
        }
        parent::tearDown();
    }

    public function testFreshWorkerClaimsBeforeBootstrapAndRunsOpaqueCallable(): void
    {
        [$ledger, $capability, $marker, $effect] = $this->fixture();
        $syncEffect = $this->directory . '/sync-effect';
        BlackBoxApplication::invokeFixtureSynchronously($syncEffect);

        $result = $this->awaitWorker($capability, $marker, $effect);
        self::assertSame(0, $result->exitCode, $result->stderr . $result->stdout);
        self::assertFileExists($marker);
        self::assertSame("fixture-effect\n", (string) file_get_contents($effect));
        self::assertSame((string) file_get_contents($syncEffect), (string) file_get_contents($effect));
        self::assertSame('succeeded', $ledger->observe($capability->occurrence)?->state);
    }

    public function testStaleCapabilityIsRejectedBeforeBootstrapAndCannotTerminalize(): void
    {
        [$ledger, $capability, $marker, $effect] = $this->fixture();
        $stale = BlackBoxApplication::capability($capability->occurrence, 'different-fence');

        $result = $this->awaitWorker($stale, $marker, $effect);
        self::assertNotSame(0, $result->exitCode);
        self::assertFileDoesNotExist($marker);
        self::assertFileDoesNotExist($effect);
        $record = $ledger->observe($capability->occurrence);
        self::assertSame('launch-required', $record?->state);
        self::assertNull($record?->outcome);
    }

    public function testAdmissionAndTerminalOutcomeAreIdempotent(): void
    {
        [$ledger, $capability, $marker, $effect] = $this->fixture();
        self::assertFalse($ledger->admit(BlackBoxApplication::record($capability))->newlyAdmitted);
        $this->awaitWorker($capability, $marker, $effect);
        $this->awaitWorker($capability, $marker, $effect);

        self::assertSame("fixture-effect\n", (string) file_get_contents($effect));
        self::assertSame('succeeded', $ledger->observe($capability->occurrence)?->state);
    }

    public function testCleanParentExitWithoutOutcomeIsRecordedAsAmbiguous(): void
    {
        [$ledger, $capability, $marker, $effect] = $this->fixture();
        self::assertSame('accepted', $ledger->reserveDispatch($capability->occurrence, new ExecutionFence($capability->generation, $capability->fenceToken), new ExecutionDispatchReservation('fixture-dispatcher', 1000, 2000))->status);
        $result = $this->awaitWorker($capability, $marker, $effect, 'exit-before-entrypoint');

        // A process result is only an observation here: it is not a task outcome.
        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->stdout);
        $observation = new ExecutionLaunchObservation(null, null, 'clean-exit-without-outcome', 2000);
        $dispatch = (new ExecutionIntegrationCoordinator($ledger, new BlackBoxApplicationSource(), new BlackBoxApplicationLauncher()))
            ->observeLaunch($capability->occurrence, new ExecutionFence($capability->generation, $capability->fenceToken), $observation);
        self::assertSame('ambiguous', $dispatch->status);
        $record = $ledger->observe($capability->occurrence);
        self::assertSame(ExecutionLedgerState::NEEDS_RESOLUTION, $record?->state);
        self::assertSame($observation->classification, $record?->launchObservation?->classification);
        self::assertLessThanOrEqual(128, strlen($record?->launchObservation?->classification ?? ''));
        self::assertNull($record?->outcome);
        self::assertFileDoesNotExist($effect);
    }

    public function testLaunchFailureDoesNotUseSynchronousFallback(): void
    {
        [$ledger, $capability, $marker, $effect] = $this->fixture();
        $observer = new BlackBoxObserver();
        $launcher = new SymfonyExecutionWorkerLauncher(new BlackBoxCommandBuilder('', $this->directory, $marker, $effect, fail: true), $observer);

        try {
            $launcher->launch(new ExecutionWorkerLaunchRequest($capability));
            self::fail('Expected builder failure.');
        } catch (Throwable) {
            self::assertFileDoesNotExist($effect);
            self::assertSame('launch-required', $ledger->observe($capability->occurrence)?->state);
            self::assertNull($ledger->observe($capability->occurrence)?->outcome);
        }
    }

    /** @return array{ExecutionLedgerInterface,ExecutionWorkerCapability,string,string} */
    private function fixture(): array
    {
        $ledgerPath = $this->directory . '/ledger';
        mkdir($ledgerPath, 0700);
        $ledger = new FileExecutionLedger($ledgerPath);
        $occurrence = new ExecutionOccurrenceKey('fixture-schedule', 'fixture-task', 'occurrence-' . bin2hex(random_bytes(4)));
        $capability = BlackBoxApplication::capability($occurrence);
        $capability = new ExecutionWorkerCapability($capability->occurrence, $capability->attemptId, $capability->runId, $capability->executionId, $capability->generation, $capability->fenceToken, $capability->configurationFingerprint, hash('sha256', PreparedConfiguration::canonical($capability->policy)), $capability->ledgerLocator, $capability->runtimeLocator, $capability->sourceRevision, $capability->policy);
        self::assertTrue($ledger->admit(BlackBoxApplication::record($capability))->newlyAdmitted);
        return [$ledger, $capability, $this->directory . '/marker', $this->directory . '/effect'];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($directory);
    }

    private function awaitWorker(ExecutionWorkerCapability $capability, string $marker, string $effect, string $mode = 'entrypoint'): ProcessResult
    {
        $observer = new BlackBoxObserver();
        $builder = new BlackBoxCommandBuilder(__DIR__ . '/Process/black-box-worker.php', $this->directory . '/ledger', $marker, $effect, $mode);
        (new SymfonyExecutionWorkerLauncher($builder, $observer))->launch(new ExecutionWorkerLaunchRequest($capability));
        $deadline = microtime(true) + 5.0;
        do {
            $result = $observer->handle?->poll();
            if ($result !== null) {
                return $result;
            }
        } while (microtime(true) < $deadline);
        $observer->handle?->terminateHard();
        self::fail('Worker did not finish before the bounded observation deadline.');
    }
}
