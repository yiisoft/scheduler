<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async\Process;

use DateTimeImmutable;
use Symfony\Component\Process\Process;
use Yiisoft\Schedule\Integration\Async\MonotonicClockInterface;

use function constant;
use function ctype_digit;
use function defined;
use function function_exists;
use function file_get_contents;
use function getmypid;
use function is_dir;
use function is_readable;
use function posix_getpgid;
use function posix_kill;
use function preg_match;
use function preg_split;
use function scandir;
use function strlen;
use function substr;
use function usleep;

use const PREG_SPLIT_NO_EMPTY;

final class SymfonyProcessHandle implements ProcessHandleInterface
{
    private ?ProcessResult $result = null;
    private string $stdout = '';
    private string $stderr = '';
    private bool $stdoutTruncated = false;
    private bool $stderrTruncated = false;
    private bool $callbackAttached = false;
    private ?int $processGroupId = null;

    public function __construct(private readonly Process $process) {}

    /** Start with a callback so output is collected and discarded incrementally. */
    public function start(?MonotonicClockInterface $clock = null): float
    {
        // Keep Symfony's own output buffers disabled. A callback still receives
        // stream data in this mode, while the adapter retains only bounded
        // diagnostics through collect().
        $this->process->disableOutput();
        $this->callbackAttached = true;
        $this->process->start($this->collect(...));

        $pid = $this->process->getPid();
        $groupId = null;
        // Process::start() returns before setsid has necessarily exec'd its
        // target. Give the pre-exec boundary a short, deterministic window;
        // once the launcher exits, do not reinterpret the race as success.
        for ($attempt = 0; $attempt < 100 && $pid !== null; ++$attempt) {
            $groupId = $this->resolveProcessGroup($pid);
            if ($groupId !== null || !$this->process->isRunning()) {
                break;
            }
            usleep(1000);
        }
        if ($groupId === null) {
            // A vanished launcher is ambiguous: its target may have escaped
            // the observable child list while leaving descendants behind.
            // Never turn that race into an empty scope or a PID fallback.
            if ($this->process->isRunning()) {
                $this->process->signal(defined('SIGKILL') ? (int) constant('SIGKILL') : 9);
            }
            throw new ProcessScopeException('Unable to establish the owned process scope.');
        }
        // The verified group is the first reliable boundary after setsid has
        // established the target's owned scope. Sample only then, so setup of
        // the wrapper and scope is excluded from the launch timestamp.
        $this->processGroupId = $groupId;
        return $clock?->now() ?? microtime(true);
    }

    public function isRunning(): bool
    {
        return $this->result === null && $this->process->isRunning();
    }

    public function isQuiescent(): bool
    {
        if ($this->process->isRunning()) {
            return false;
        }
        if ($this->processGroupId === null) {
            return true;
        }

        // kill(2) reports a process group containing only zombies as alive.
        // Inspect the members instead: a zombie cannot execute work and is
        // therefore not a reason to keep the handle open forever.
        return $this->processGroupHasLiveMembers() === false;
    }

    public function poll(): ?ProcessResult
    {
        if ($this->result !== null) {
            return $this->result;
        }
        if (!$this->isQuiescent()) {
            return null;
        }
        if (!$this->callbackAttached) {
            $this->collect(Process::OUT, $this->process->getIncrementalOutput());
            $this->collect(Process::ERR, $this->process->getIncrementalErrorOutput());
        }
        $signal = $this->process->hasBeenSignaled() ? $this->process->getTermSignal() : null;
        $this->result = new ProcessResult(
            $this->process->getExitCode(),
            $signal,
            $this->stdout,
            $this->stderr,
            new DateTimeImmutable(),
            $this->stdoutTruncated,
            $this->stderrTruncated,
        );
        return $this->result;
    }

    public function terminateSoft(): void
    {
        $this->terminate(defined('SIGTERM') ? (int) constant('SIGTERM') : 15);
    }

    public function terminateHard(): void
    {
        $this->terminate(defined('SIGKILL') ? (int) constant('SIGKILL') : 9);
    }

    private function resolveProcessGroup(int $pid): ?int
    {
        if (!$this->ownedScopeSupported()) {
            return null;
        }
        if (posix_getpgid($pid) === $pid) {
            return $pid;
        }
        $children = @file_get_contents('/proc/' . $pid . '/task/' . $pid . '/children');
        $childEntries = preg_split('/\\s+/', trim((string) $children), -1, PREG_SPLIT_NO_EMPTY);
        if ($childEntries === false) {
            return null;
        }
        foreach ($childEntries as $child) {
            $childPid = (int) $child;
            if ($childPid > 0 && posix_getpgid($childPid) === $childPid) {
                return $childPid;
            }
        }
        return null;
    }

    private function terminate(int $signal): void
    {
        if ($this->processGroupId !== null) {
            if (!posix_kill(-$this->processGroupId, $signal) && $this->processGroupHasLiveMembers() !== false) {
                throw new ProcessScopeException('Unable to terminate the owned process scope.');
            }
            return;
        }
        // Handles created by this adapter always have a group.  Keep this
        // branch for portable test doubles and avoid asking isRunning twice.
        if ($this->process->isRunning()) {
            $this->process->signal($signal);
        }
    }

    private function ownedScopeSupported(): bool
    {
        $pid = getmypid();
        return $pid !== false
            && function_exists('posix_kill')
            && function_exists('posix_getpgid')
            && is_readable('/proc/self/task/' . $pid . '/children');
    }

    /**
     * Return whether this scope has an executable member.
     *
     * A null result is deliberately distinct from false: an unreadable
     * process table must not be interpreted as a successfully empty scope.
     */
    private function processGroupHasLiveMembers(): ?bool
    {
        if ($this->processGroupId === null) {
            return false;
        }

        $entries = scandir('/proc');
        if ($entries === false) {
            return null;
        }

        foreach ($entries as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }
            $stat = @file_get_contents('/proc/' . $entry . '/stat');
            if ($stat === false) {
                // A process can disappear between scandir() and reading its
                // stat file. That is a normal exit race (especially with the
                // nested setsid scopes), not evidence that this owned group is
                // still live. Preserve the fail-closed behavior for an entry
                // that still exists but cannot be inspected.
                if (!is_dir('/proc/' . $entry)) {
                    continue;
                }
                return null;
            }
            if (preg_match('/^\\d+ \\(.*\\) ([A-Z]) \\d+ (\\d+)/', $stat, $matches) !== 1) {
                return null;
            }
            if ((int) $matches[2] === $this->processGroupId && $matches[1] !== 'Z') {
                return true;
            }
        }

        return false;
    }

    private function collect(string $type, string $data): void
    {
        $output = $type === Process::OUT ? $this->stdout : $this->stderr;
        $remaining = ProcessResult::MAX_DIAGNOSTIC_LENGTH - strlen($output);
        if (strlen($data) > $remaining) {
            if ($type === Process::OUT) {
                $this->stdoutTruncated = true;
            } else {
                $this->stderrTruncated = true;
            }
        }
        if ($remaining <= 0) {
            return;
        }

        $output .= substr($data, 0, $remaining);
        if ($type === Process::OUT) {
            $this->stdout = $output;
        } else {
            $this->stderr = $output;
        }
    }
}
