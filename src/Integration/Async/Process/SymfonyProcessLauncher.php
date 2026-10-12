<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use Symfony\Component\Process\Process;
use Throwable;
use Yiisoft\Scheduler\Integration\Async\MonotonicClockInterface;
use Yiisoft\Scheduler\Integration\Async\SystemMonotonicClock;

use function file_get_contents;
use function function_exists;
use function getmypid;
use function is_executable;
use function is_file;
use function is_readable;
use function preg_match;

use const PHP_BINARY;

final class SymfonyProcessLauncher implements ProcessLauncherInterface
{
    public function __construct(private readonly MonotonicClockInterface $clock = new SystemMonotonicClock()) {}

    public static function supportsOwnedProcessScope(): bool
    {
        $pid = getmypid();
        return $pid !== false
            && function_exists('posix_kill')
            && function_exists('posix_getpgid')
            && is_readable('/proc/self/task/' . $pid . '/children')
            && self::supportsProcessTableObservation()
            && self::findTrustedSetsid() !== null;
    }

    public function launch(ProcessLaunchRequest $request): ProcessLaunchResult
    {
        if (!self::supportsOwnedProcessScope()) {
            throw new ProcessScopeException('Owned process scopes are not supported on this platform.');
        }

        // setsid establishes the session and process group before exec.  Wait
        // keeps the Symfony-owned process alive while that group is resolved.
        $setsid = self::findTrustedSetsid();
        if ($setsid === null) {
            throw new ProcessScopeException('Unable to establish the owned process scope.');
        }
        $process = new Process([$setsid, '--wait', $request->executable, ...$request->argv], $request->cwd, $request->environment);
        $process->setInput($request->protocolInput);
        $handle = new SymfonyProcessHandle($process);
        $startedAt = $handle->start($this->clock);
        return new ProcessLaunchResult($handle, $startedAt);
    }

    private static function findTrustedSetsid(): ?string
    {
        // Do not consult PATH: it is application/environment input and may
        // select an attacker-controlled replacement. These are the standard
        // system locations permitted by this adapter's deployment policy.
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $setsid) {
            if (!is_file($setsid) || !is_executable($setsid) || !self::supportsWait($setsid)) {
                continue;
            }

            return $setsid;
        }

        return null;
    }

    private static function supportsWait(string $setsid): bool
    {
        try {
            // A successful zero-exit probe cannot distinguish --wait from an
            // immediate setsid exit. Require the status of a deterministic,
            // non-zero child completion to be propagated by setsid.
            $probe = new Process([$setsid, '--wait', PHP_BINARY, '-r', 'exit(23);']);
            $probe->setTimeout(2.0);

            return $probe->run() === 23;
        } catch (Throwable) {
            return false;
        }
    }

    private static function supportsProcessTableObservation(): bool
    {
        if (!is_readable('/proc')) {
            return false;
        }

        $pid = getmypid();
        if ($pid === false) {
            return false;
        }

        // Do not scan every process while deciding whether the capability exists.
        // Entries can disappear between scandir() and stat() while unrelated
        // processes exit. The actual scope observer remains fail-closed when it
        // reads the owned group's members.
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        return $stat !== false && preg_match('/^\\d+ \\(.*\\) ([A-Z]) \\d+ (\\d+)/', $stat) === 1;
    }
}
