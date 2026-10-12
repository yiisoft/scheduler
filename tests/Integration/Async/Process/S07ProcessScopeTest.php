<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Process;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Yiisoft\Scheduler\Integration\Async\Process\{ProcessHandleInterface, ProcessLaunchRequest, ProcessScopeException, SymfonyProcessHandle, SymfonyProcessLauncher};

use function chmod;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_array;
use function is_executable;
use function is_file;
use function microtime;
use function mkdir;
use function posix_getpgid;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

use const PHP_BINARY;

final class S07ProcessScopeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        // The launcher deliberately has no injectable capability, filesystem,
        // or signal seam. Consequently BusyBox/no-`--wait`, unavailable POSIX
        // capability, zombie-only groups, and group-kill failure cannot be
        // forced deterministically here without brittle skips or environment
        // mutation. The real-scope tests below cover the observable contract;
        // the PATH trust boundary is tested with a harmless fake command.
        if (!SymfonyProcessLauncher::supportsOwnedProcessScope() || !is_executable(PHP_BINARY)) {
            self::markTestSkipped('The POSIX owned-process-scope capability is unavailable.');
        }
        $this->directory = sys_get_temp_dir() . '/yii-schedule-s07-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory)) {
            return;
        }
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
    }

    public function testWorkerAndPersistentDescendantShareVerifiedProcessGroup(): void
    {
        [$handle, $parent, $child] = $this->launch('persistent');
        self::assertSame((int) $parent['pgid'], (int) $parent['pid']);
        self::assertSame((int) $parent['pgid'], (int) $child['pgid']);
        self::assertSame((int) $parent['pgid'], posix_getpgid((int) $child['pid']));
        self::assertTrue($handle->isRunning());
        self::assertFalse($handle->isQuiescent());

        $handle->terminateHard();
        $result = $this->pollToCompletion($handle);
        self::assertNotNull($result);
        self::assertTrue($handle->isQuiescent());
    }

    public function testSoftThenHardTerminationReachesQuiescenceAndPollCompletes(): void
    {
        [$handle] = $this->launch('persistent');
        $handle->terminateSoft();
        // Hard termination is intentionally safe after soft termination: the
        // scope may have disappeared between the two calls.
        $handle->terminateHard();

        $result = $this->pollToCompletion($handle);
        self::assertNotNull($result);
        self::assertSame($result, $handle->poll());
        self::assertTrue($handle->isQuiescent());
    }

    public function testFastParentWithRemainingDescendantIsAmbiguousNotAnEmptyScope(): void
    {
        $childPath = $this->directory . '/child.json';
        $process = null;
        $rejected = false;
        try {
            try {
                $process = (new SymfonyProcessLauncher())->launch(new ProcessLaunchRequest(
                    PHP_BINARY,
                    [__DIR__ . '/s07-scope-worker.php', 'fast', $childPath],
                    null,
                    [],
                    '',
                ))->handle;
                // A returned handle is acceptable only when it retains the
                // descendant scope; it must not report an empty successful run.
                self::assertFalse($process->isQuiescent());
                self::assertNull($process->poll());
                $process->terminateHard();
                self::assertNotNull($this->pollToCompletion($process));
            } catch (ProcessScopeException) {
                $rejected = true;
                self::addToAssertionCount(1);
            }
        } finally {
            if ($process !== null) {
                $process->terminateHard();
            }
        }
        self::assertTrue($rejected || $process !== null, 'Fast-parent handling must be rejection or an owned ambiguous handle.');
    }

    public function testTrustedSetsidIsUsedInsteadOfPATHCommand(): void
    {
        $fakeSetsid = $this->directory . '/setsid';
        file_put_contents($fakeSetsid, "#!/bin/sh\nexit 99\n");
        chmod($fakeSetsid, 0700);

        $handle = (new SymfonyProcessLauncher())->launch(new ProcessLaunchRequest(
            PHP_BINARY,
            ['-r', 'exit(0);'],
            null,
            ['PATH' => $this->directory],
            '',
        ))->handle;

        $result = $this->pollToCompletion($handle);
        self::assertNotNull($result);
        self::assertSame(0, $result->exitCode);
    }

    public function testAlreadyExitedHandleIsIdempotent(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isRunning')->willReturn(false);
        $handle = new SymfonyProcessHandle($process);

        $handle->terminateSoft();
        $handle->terminateHard();
        self::assertTrue($handle->isQuiescent());
    }

    public function testTerminationFailureIsPropagatedExplicitly(): void
    {
        $process = $this->createMock(Process::class);
        $process->expects(self::once())->method('isRunning')->willReturn(true);
        $process->expects(self::once())->method('signal')->willThrowException(new RuntimeException('signal failed'));
        $handle = new SymfonyProcessHandle($process);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('signal failed');
        $handle->terminateHard();
    }

    /** @return array{ProcessHandleInterface,array<string,int>,array<string,int>} */
    private function launch(string $mode): array
    {
        $parentPath = $this->directory . '/parent.json';
        $childPath = $this->directory . '/child.json';
        $handle = (new SymfonyProcessLauncher())->launch(new ProcessLaunchRequest(
            PHP_BINARY,
            [__DIR__ . '/s07-scope-worker.php', $mode, $parentPath, $childPath],
            null,
            [],
            '',
        ))->handle;
        $parent = $this->readJsonWhenReady($parentPath);
        $child = $this->readJsonWhenReady($childPath);
        return [$handle, $parent, $child];
    }

    /** @return array<string,int> */
    private function readJsonWhenReady(string $path): array
    {
        $deadline = microtime(true) + 2.0;
        do {
            if (is_file($path)) {
                $data = json_decode((string) file_get_contents($path), true);
                if (is_array($data) && isset($data['pid'], $data['pgid'])) {
                    return ['pid' => (int) $data['pid'], 'pgid' => (int) $data['pgid']];
                }
            }
        } while (microtime(true) < $deadline);
        self::fail('Process scope fixture did not publish its identity.');
    }

    private function pollToCompletion(ProcessHandleInterface $handle): ?object
    {
        $deadline = microtime(true) + 3.0;
        do {
            $result = $handle->poll();
            if ($result !== null) {
                return $result;
            }
        } while (microtime(true) < $deadline);
        self::fail('Owned process scope did not become quiescent.');
    }
}
