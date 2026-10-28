<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Process;

use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchDescriptor;
use Yiisoft\Scheduler\Integration\Async\Process\{AttachedProcessHandle, LinuxProcessObserver, ProcessIdentity, ProcessLookupResult, ProcessScopeException, ProcessScopeProof, ProcessLaunchRequest, SymfonyProcessLauncher};

use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function microtime;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function usleep;
use function unlink;

use const PHP_BINARY;
use const PHP_OS_FAMILY;

final class OwnedScopeClosureTest extends TestCase
{
    private string $proc;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('Linux process identity is required.');
        }
        $this->proc = sys_get_temp_dir() . '/yii-owned-scope-' . bin2hex(random_bytes(8));
        mkdir($this->proc . '/sys/kernel/random', 0700, true);
        file_put_contents($this->proc . '/sys/kernel/random/boot_id', "fixture-boot\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->proc . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                foreach (glob($path . '/*') ?: [] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($this->proc);
    }

    public function testLeaderExitWithLiveDescendantRemainsLiveAndPollIsNotReleased(): void
    {
        $metadata = $this->proc . '/facts.json';
        $release = $this->proc . '/release';
        $descriptor = new ExecutionLaunchDescriptor('fixture', 1, 'nonce');
        $launch = (new SymfonyProcessLauncher())->launch(new ProcessLaunchRequest(
            PHP_BINARY,
            [__DIR__ . '/owned-scope-fixture.php', $metadata, $release],
            null,
            ['YII_SCHEDULER_WORKER_MARKER' => $descriptor->marker()],
            '',
            $descriptor,
        ));
        $this->waitFor($metadata);
        self::assertTrue($launch->handle->isRunning());
        self::assertNull($launch->handle->poll());
        file_put_contents($release, 'release');
        for ($i = 0; $i < 200 && !$launch->handle->isQuiescent(); ++$i) {
            $launch->handle->poll();
        }
        self::assertTrue($launch->handle->isQuiescent());
    }

    public function testObserverMatchHasLiveProofAndAttachedPollContract(): void
    {
        $descriptor = new ExecutionLaunchDescriptor('fixture', 2, 'nonce');
        $this->writeProcess(101, 101, 101, 'S', $descriptor->marker(), '10101');
        $result = (new LinuxProcessObserver($this->proc))->lookup($descriptor);
        self::assertSame(ProcessLookupResult::MATCH, $result->status);
        self::assertInstanceOf(AttachedProcessHandle::class, $result->handle);
        self::assertSame(ProcessScopeProof::LIVE, $result->proof?->status);
        self::assertNull($result->handle->poll());
    }

    public function testTerminalMembersCloseWithoutWaitingForHostReaping(): void
    {
        $descriptor = new ExecutionLaunchDescriptor('fixture', 3, 'nonce');
        $identity = $this->identity(201, 201, 201, '20101', $descriptor);
        $this->writeProcess(201, 201, 201, 'Z', $descriptor->marker(), '20101');
        $handle = new AttachedProcessHandle($identity, $this->proc);
        self::assertSame(ProcessScopeProof::CLOSED_TERMINAL, $handle->scopeProof()->status);
        self::assertNotNull($handle->poll());
    }

    public function testEmptyGroupRequiresKernelEsrch(): void
    {
        $descriptor = new ExecutionLaunchDescriptor('fixture', 4, 'nonce');
        $handle = new AttachedProcessHandle($this->identity(301, 999999, 999999, '30101', $descriptor), $this->proc);
        self::assertSame(ProcessScopeProof::CLOSED_ESRCH, $handle->scopeProof()->status);
    }

    public function testUnreadableReusedAndConflictingMembersAreUncertain(): void
    {
        $descriptor = new ExecutionLaunchDescriptor('fixture', 5, 'nonce');
        mkdir($this->proc . '/401');
        $unreadable = new AttachedProcessHandle($this->identity(401, 401, 401, '40101', $descriptor), $this->proc);
        self::assertSame(ProcessScopeProof::UNCERTAIN, $unreadable->scopeProof()->status);

        $this->writeProcess(402, 402, 402, 'S', $descriptor->marker(), 'different');
        $reused = new AttachedProcessHandle($this->identity(402, 402, 402, '40201', $descriptor), $this->proc);
        self::assertSame(ProcessScopeProof::UNCERTAIN, $reused->scopeProof()->status);

        $this->writeProcess(403, 403, 404, 'S', $descriptor->marker(), '40301');
        $conflict = new AttachedProcessHandle($this->identity(403, 403, 403, '40301', $descriptor), $this->proc);
        self::assertSame(ProcessScopeProof::UNCERTAIN, $conflict->scopeProof()->status);
    }

    public function testUncertainLiveProbeCannotSignalAndIsolatedProbeIsNotClosure(): void
    {
        $descriptor = new ExecutionLaunchDescriptor('fixture', 6, 'nonce');
        $this->writeProcess(501, 501, 501, 'S', $descriptor->marker(), '50101');
        $handle = new AttachedProcessHandle($this->identity(501, 501, 501, '50101', $descriptor), $this->proc);
        self::assertSame(ProcessScopeProof::LIVE, $handle->scopeProof()->status);
        try {
            $handle->terminateHard();
            self::fail('The isolated signal probe must not be treated as closure.');
        } catch (ProcessScopeException) {
            self::addToAssertionCount(1);
        }

        mkdir($this->proc . '/502');
        $uncertain = new AttachedProcessHandle($this->identity(502, 502, 502, '50201', $descriptor), $this->proc);
        self::assertSame(ProcessScopeProof::UNCERTAIN, $uncertain->scopeProof()->status);
        $this->expectException(ProcessScopeException::class);
        $uncertain->terminateHard();
    }

    private function waitFor(string $path): void
    {
        $deadline = microtime(true) + 2.0;
        while (!is_file($path) && microtime(true) < $deadline) {
            usleep(1000);
        }
        self::assertFileExists($path);
    }

    private function identity(int $pid, int $pgid, int $session, string $ticks, ExecutionLaunchDescriptor $descriptor): ProcessIdentity
    {
        return new ProcessIdentity($pid, $pgid, $session, $ticks, 'fixture-boot', $descriptor);
    }

    private function writeProcess(int $pid, int $pgid, int $session, string $state, string $marker, string $ticks): void
    {
        mkdir($this->proc . '/' . $pid, 0700, true);
        $fields = [$pid, '(fixture)', $state, 1, $pgid, $session, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, $ticks];
        file_put_contents($this->proc . '/' . $pid . '/stat', implode(' ', $fields));
        file_put_contents($this->proc . '/' . $pid . '/environ', 'YII_SCHEDULER_WORKER_MARKER=' . $marker . "\0");
    }
}
