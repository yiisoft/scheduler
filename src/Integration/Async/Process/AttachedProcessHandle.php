<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use DateTimeImmutable;

use function ctype_digit;
use function file_get_contents;
use function is_dir;
use function posix_kill;
use function preg_split;
use function function_exists;
use function scandir;
use function trim;
use function constant;
use function count;
use function defined;

use const PREG_SPLIT_NO_EMPTY;

/** Reattached handle which observes the already authenticated process scope. */
final class AttachedProcessHandle implements ProcessHandleInterface
{
    private ?ProcessResult $result = null;
    private ProcessScopeProof $proof;

    public function __construct(public readonly ProcessIdentity $identity, private readonly string $procPath = '/proc')
    {
        $this->proof = ProcessScopeProof::liveFromLookup($identity->descriptor, $identity);
    }

    public function scopeProof(): ProcessScopeProof
    {
        $this->observe();
        return $this->proof;
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
        if (!$this->scopeProof()->isClosed()) {
            return null;
        }
        return $this->result = new ProcessResult(null, null, '', '', new DateTimeImmutable(), false, false);
    }

    public function terminateSoft(): void
    {
        $this->signal(15);
    }

    public function terminateHard(): void
    {
        $this->signal(9);
    }

    private function observe(): void
    {
        // A signal can change a member's /proc state (or reap it) between the
        // two reads used for identity validation. Retry only that explicit
        // race; every other uncertainty remains fail-closed.
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->observeOnce();
            if ($this->proof->reason !== ProcessScopeProof::REASON_RACE) {
                return;
            }
        }
    }

    private function observeOnce(): void
    {
        $boot = @file_get_contents($this->procPath . '/sys/kernel/random/boot_id');
        if ($boot === false || trim($boot) !== $this->identity->bootId) {
            $this->proof = ProcessScopeProof::uncertainFromRetainedPoll($this->identity->descriptor, ProcessScopeProof::REASON_UNSUPPORTED, $this->identity);
            return;
        }
        $entries = scandir($this->procPath);
        if ($entries === false) {
            $this->proof = ProcessScopeProof::uncertainFromRetainedPoll($this->identity->descriptor, ProcessScopeProof::REASON_UNSUPPORTED, $this->identity);
            return;
        }
        $members = [];
        foreach ($entries as $entry) {
            if (!ctype_digit($entry) || (int) $entry < 1) {
                continue;
            }
            $path = $this->procPath . '/' . $entry;
            $stat = @file_get_contents($path . '/stat');
            if ($stat === false) {
                if (is_dir($path)) {
                    $this->uncertain(ProcessScopeProof::REASON_UNREADABLE);
                    return;
                }
                continue;
            }
            $facts = $this->statFields($stat);
            if ($facts === null) {
                $this->uncertain(ProcessScopeProof::REASON_RACE);
                return;
            }
            // Inspect the persisted leader independently of membership so a
            // reused PID cannot disappear from the proof merely by changing
            // its process group.
            if ((int) $facts[1] === $this->identity->pid && ($facts[22] !== $this->identity->startTicks || (int) $facts[5] !== $this->identity->processGroupId || (int) $facts[6] !== $this->identity->sessionId)) {
                $this->uncertain(ProcessScopeProof::REASON_GROUP_REUSE);
                return;
            }
            if ((int) $facts[5] !== $this->identity->processGroupId) {
                continue;
            }
            if ((int) $facts[6] !== $this->identity->sessionId) {
                $this->uncertain(ProcessScopeProof::REASON_IDENTITY_CONFLICT);
                return;
            }
            $members[] = $facts;
        }
        $terminalOnly = $members !== [];
        foreach ($members as $facts) {
            if ($facts[3] !== 'Z' && $facts[3] !== 'X') {
                $terminalOnly = false;
            }
        }
        // Reread every known member before classifying, including when the
        // authenticated leader has exited and live descendants remain.
        foreach ($members as $member) {
            $again = @file_get_contents($this->procPath . '/' . $member[1] . '/stat');
            $second = $again === false ? null : $this->statFields($again);
            if ($second === null || $second[22] !== $member[22] || (int) $second[5] !== (int) $member[5] || (int) $second[6] !== (int) $member[6] || $second[3] !== $member[3]) {
                $this->uncertain(ProcessScopeProof::REASON_RACE);
                return;
            }
        }
        if ($members !== []) {
            $this->proof = $terminalOnly
                ? ProcessScopeProof::closedTerminalFromRetainedPoll($this->identity->descriptor, $this->identity)
                : ProcessScopeProof::liveFromRetainedPoll($this->identity->descriptor, $this->identity);
            return;
        }
        // An empty table is closure only when the kernel confirms ESRCH. EPERM
        // (or an unavailable provider) is deliberately not absence.
        if (!function_exists('posix_kill')) {
            $this->uncertain(ProcessScopeProof::REASON_UNSUPPORTED);
            return;
        }
        if (!@posix_kill(-$this->identity->processGroupId, 0)) {
            // PHP exposes errno separately from the boolean result. Only a
            // kernel-confirmed ESRCH proves that the group is absent; EPERM,
            // an unavailable errno provider, and all other failures are not
            // safe to interpret as closure.
            $errno = function_exists('posix_get_last_error') ? posix_get_last_error() : null;
            $esrch = defined('POSIX_ESRCH') ? (int) constant('POSIX_ESRCH') : 3;
            if ($errno !== null && $errno === $esrch) {
                $this->proof = ProcessScopeProof::closedEsrchFromRetainedPoll($this->identity->descriptor, $this->identity);
                return;
            }
            $this->uncertain($errno === (defined('POSIX_EPERM') ? (int) constant('POSIX_EPERM') : 1)
                ? ProcessScopeProof::REASON_EPERM
                : ProcessScopeProof::REASON_UNSUPPORTED);
            return;
        }
        $this->uncertain(ProcessScopeProof::REASON_EPERM);
    }

    private function uncertain(string $reason): void
    {
        $this->proof = ProcessScopeProof::uncertainFromRetainedPoll($this->identity->descriptor, $reason, $this->identity);
    }

    private function signal(int $signal): void
    {
        $proof = $this->scopeProof();
        if ($proof->status !== ProcessScopeProof::LIVE) {
            if ($proof->isClosed()) {
                return;
            }
            throw new ProcessScopeException('Unable to revalidate the process scope.');
        }
        if (!function_exists('posix_kill') || !@posix_kill(-$this->identity->processGroupId, $signal)) {
            $after = $this->scopeProof();
            if (!$after->isClosed()) {
                throw new ProcessScopeException('Unable to terminate the process scope.');
            }
        }
    }

    /** @return array<int,string>|null */
    private function statFields(string $stat): ?array
    {
        $open = strpos($stat, ' (');
        $close = strrpos($stat, ')');
        if ($open === false || $close === false || $close <= $open) {
            return null;
        }
        $pid = substr($stat, 0, $open);
        $suffix = preg_split('/\s+/', trim(substr($stat, $close + 1)), -1, PREG_SPLIT_NO_EMPTY);
        if (!ctype_digit($pid) || $suffix === false || count($suffix) < 20) {
            return null;
        }
        $fields = [1 => $pid];
        foreach ($suffix as $offset => $value) {
            $fields[$offset + 3] = $value;
        }
        return ctype_digit($fields[22]) ? $fields : null;
    }
}
