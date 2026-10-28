<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use DateTimeImmutable;
use Symfony\Component\Process\Process;
use Yiisoft\Scheduler\Integration\Async\{ExecutionLaunchDescriptor, MonotonicClockInterface};

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
    private ?int $wallStartedAtMicros = null;
    private ?ProcessIdentity $identity = null;
    private readonly ExecutionLaunchDescriptor $descriptor;

    public function __construct(private readonly Process $process, ?ExecutionLaunchDescriptor $descriptor = null)
    {
        $this->descriptor = $descriptor ?? new ExecutionLaunchDescriptor('unbound', 1, 'unbound');
    }

    public function bindIdentity(ProcessIdentity $identity): void
    {
        if (!$this->descriptor->equals($identity->descriptor)) {
            throw new ProcessScopeException('Launched identity does not match its descriptor.');
        }
        $this->identity = $identity;
        $this->processGroupId = $identity->processGroupId;
    }

    public function scopeProof(): ProcessScopeProof
    {
        if ($this->identity === null) {
            return ProcessScopeProof::uncertainFromRetainedPoll($this->descriptor, ProcessScopeProof::REASON_UNBOUND_SCOPE);
        }
        $attached = new AttachedProcessHandle($this->identity);
        return $attached->scopeProof();
    }

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
        // Both samples are taken at the owned-scope boundary, never by a
        // later supervisor poll. The monotonic value is used only in-process.
        $this->wallStartedAtMicros = (int) floor(microtime(true) * 1000000.0);
        return $clock?->now() ?? microtime(true);
    }

    public function wallStartedAtMicros(): ?int
    {
        return $this->wallStartedAtMicros;
    }

    public function processGroupId(): ?int
    {
        return $this->processGroupId;
    }

    public function isRunning(): bool
    {
        return $this->scopeProof()->status === ProcessScopeProof::LIVE;
    }

    public function isQuiescent(): bool
    {
        return $this->scopeProof()->isClosed();
    }

    public function poll(): ?ProcessResult
    {
        if ($this->result !== null) {
            return $this->result;
        }
        // Advance Symfony's pipe/callback state even while scope proof is
        // being obtained from /proc; otherwise a verbose child can block on
        // a full pipe while its setsid wrapper remains apparently live.
        $this->process->isRunning();
        if (!$this->scopeProof()->isClosed()) {
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
        $proof = $this->scopeProof();
        if ($proof->status !== ProcessScopeProof::LIVE) {
            if ($proof->isClosed()) {
                return;
            }
            throw new ProcessScopeException('Unable to safely signal an unproven process scope.');
        }
        if ($this->processGroupId === null || !@posix_kill(-$this->processGroupId, $signal)) {
            $after = $this->scopeProof();
            if (!$after->isClosed()) {
                throw new ProcessScopeException('Unable to terminate the owned process scope.');
            }
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
