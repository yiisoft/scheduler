<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use Yiisoft\Scheduler\Integration\Async\{ExecutionLaunchDescriptor, ExecutionProcessIdentity};
use Yiisoft\Scheduler\Integration\Async\Recovery\RecoveryProcessObserverInterface;

use function count;
use function ctype_digit;
use function file_get_contents;
use function is_array;
use function is_dir;
use function is_int;
use function is_readable;
use function in_array;
use function preg_split;
use function scandir;
use function strlen;
use function str_starts_with;
use function trim;
use function usort;

use const PHP_OS_FAMILY;
use const PREG_SPLIT_NO_EMPTY;

/** Linux-only, fail-closed process identity observation. */
final class LinuxProcessObserver implements RecoveryProcessObserverInterface
{
    public function __construct(private readonly string $procPath = '/proc') {}

    public function lookup(ExecutionLaunchDescriptor $descriptor, ?ExecutionProcessIdentity $expected = null): ProcessLookupResult
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_readable($this->procPath) || !is_readable($this->procPath . '/sys/kernel/random/boot_id')) {
            return new ProcessLookupResult(ProcessLookupResult::UNSUPPORTED, null, null, ProcessScopeProof::uncertainFromLookup($descriptor, ProcessScopeProof::REASON_UNSUPPORTED));
        }
        if ($descriptor->scopeKind !== ExecutionLaunchDescriptor::SCOPE_KIND) {
            return new ProcessLookupResult(ProcessLookupResult::UNSUPPORTED);
        }
        $boot = file_get_contents($this->procPath . '/sys/kernel/random/boot_id');
        if ($boot === false || trim($boot) === '') {
            return new ProcessLookupResult(ProcessLookupResult::UNREADABLE, null, null, ProcessScopeProof::uncertainFromLookup($descriptor, ProcessScopeProof::REASON_UNREADABLE));
        }
        $boot = trim($boot);

        // Recovery has a durable identity: do not let an unrelated, unreadable
        // /proc entry decide whether that identity can be checked. Inspect the
        // recorded PID first, then revalidate every persisted scope fact.
        if ($expected !== null) {
            return $this->lookupExpected($descriptor, $expected, $boot);
        }

        $entries = scandir($this->procPath);
        if ($entries === false) {
            return new ProcessLookupResult(ProcessLookupResult::UNREADABLE, null, null, ProcessScopeProof::uncertainFromLookup($descriptor, ProcessScopeProof::REASON_UNREADABLE));
        }
        $found = [];
        $groups = [];
        $groupStates = [];
        $unreadable = false;
        foreach ($entries as $entry) {
            if (!ctype_digit($entry) || (int) $entry < 1) {
                continue;
            }
            $base = $this->procPath . '/' . $entry;
            $stat = @file_get_contents($base . '/stat');
            $environ = @file_get_contents($base . '/environ');
            // A numeric entry which still exists but cannot be read is not an
            // absent process.  In particular, never turn this into no-match.
            if ($stat === false || $environ === false) {
                // Directory scans race with short-lived processes. Confirm a
                // disappearance before ignoring it; a persistent entry is an
                // explicit observation failure, never proof of no match.
                clearstatcache(true, $base);
                if (!is_dir($base)) {
                    clearstatcache(true, $base);
                    if (!is_dir($base)) {
                        continue;
                    }
                }
                $unreadable = true;
                continue;
            }
            $fields = $this->statFields($stat);
            if ($fields === null) {
                return new ProcessLookupResult(ProcessLookupResult::BLOCKED, null, null, ProcessScopeProof::uncertainFromLookup($descriptor, ProcessScopeProof::REASON_RACE));
            }
            if ((int) $fields[1] !== (int) $entry) {
                return new ProcessLookupResult(ProcessLookupResult::BLOCKED, null, null, ProcessScopeProof::uncertainFromLookup($descriptor, ProcessScopeProof::REASON_RACE));
            }
            $groups[(int) $fields[5]][(int) $fields[6]] = true;
            $groupStates[(int) $fields[5]][] = $fields[3];
            // Every process in a discovered group must belong to the same
            // session. A conflicting member makes the scope unverifiable.
            if ($found !== [] && (int) $fields[5] === $found[0]->processGroupId && (int) $fields[6] !== $found[0]->sessionId) {
                return new ProcessLookupResult(ProcessLookupResult::BLOCKED);
            }
            $marker = null;
            $markerName = $descriptor->markerName();
            foreach (explode("\0", $environ) as $variable) {
                if (str_starts_with($variable, $markerName . '=')) {
                    $marker = substr($variable, strlen($markerName) + 1);
                    break;
                }
            }
            if ($marker !== $descriptor->marker()) {
                continue;
            }
            if ((int) $fields[5] < 1 || (int) $fields[6] < 1) {
                return new ProcessLookupResult(ProcessLookupResult::MISMATCHED, null, null, ProcessScopeProof::uncertainFromLookup($descriptor, ProcessScopeProof::REASON_IDENTITY_CONFLICT));
            }
            $identity = new ProcessIdentity((int) $fields[1], (int) $fields[5], (int) $fields[6], $fields[22], $boot, $descriptor);
            $found[] = $identity;
        }
        if ($found === []) {
            return new ProcessLookupResult($unreadable ? ProcessLookupResult::UNREADABLE : ProcessLookupResult::NO_MATCH);
        }
        // The marker is inherited by the trusted setsid wrapper and its
        // worker. They are one scope, not two competing owners. A marker in
        // another group is still ambiguous, however.
        $processGroup = $found[0]->processGroupId;
        $session = $found[0]->sessionId;
        foreach ($found as $candidate) {
            if ($candidate->processGroupId !== $processGroup) {
                return new ProcessLookupResult(ProcessLookupResult::AMBIGUOUS);
            }
            if ($candidate->sessionId !== $session) {
                return new ProcessLookupResult(ProcessLookupResult::BLOCKED, null, null, ProcessScopeProof::uncertainFromLookup($descriptor, ProcessScopeProof::REASON_IDENTITY_CONFLICT));
            }
        }
        $groupSessions = $this->validatedGroupSessions($groups[$processGroup] ?? null);
        if ($groupSessions === null || count($groupSessions) !== 1 || !isset($groupSessions[$session])) {
            return new ProcessLookupResult(ProcessLookupResult::BLOCKED, null, null, ProcessScopeProof::uncertainFromLookup($descriptor, ProcessScopeProof::REASON_IDENTITY_CONFLICT));
        }
        usort($found, static fn(ProcessIdentity $left, ProcessIdentity $right): int => $left->pid <=> $right->pid);
        $identity = $found[0];
        foreach ($found as $candidate) {
            if ($candidate->pid === $processGroup) {
                $identity = $candidate;
                break;
            }
        }
        $terminalOnly = true;
        foreach ($groupStates[$processGroup] ?? [] as $state) {
            if (!in_array($state, ['Z', 'X'], true)) {
                $terminalOnly = false;
                break;
            }
        }
        if ($terminalOnly) {
            return new ProcessLookupResult(ProcessLookupResult::CLOSED, proof: ProcessScopeProof::closedTerminalFromLookup($descriptor, $identity));
        }
        return new ProcessLookupResult(ProcessLookupResult::MATCH, $identity, new AttachedProcessHandle($identity, $this->procPath), ProcessScopeProof::liveFromLookup($descriptor, $identity));
    }

    /** Capture the kernel identity at the owned launch boundary, without requiring a marker. */
    public function captureLaunchIdentity(int $pid, int $processGroupId, ExecutionLaunchDescriptor $descriptor): ?ProcessIdentity
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_readable($this->procPath)) {
            return null;
        }
        $boot = @file_get_contents($this->procPath . '/sys/kernel/random/boot_id');
        $stat = @file_get_contents($this->procPath . '/' . $pid . '/stat');
        if ($boot === false || trim($boot) === '' || $stat === false) {
            return null;
        }
        $fields = $this->statFields($stat);
        $boot = trim($boot);
        if ($fields === null || (int) $fields[1] !== $pid || (int) $fields[5] !== $processGroupId || (int) $fields[5] < 1 || (int) $fields[6] < 1) {
            return null;
        }
        return new ProcessIdentity($pid, (int) $fields[5], (int) $fields[6], $fields[22], $boot, $descriptor);
    }

    public function attach(ExecutionLaunchDescriptor $descriptor, ?ExecutionProcessIdentity $expected = null): AttachedProcessHandle
    {
        $result = $this->lookup($descriptor, $expected);
        if (!$result->matched() || $result->handle === null || !$result->handle instanceof AttachedProcessHandle) {
            throw new ProcessScopeException('Process identity could not be verified for reattachment.');
        }
        return $result->handle;
    }

    private function lookupExpected(ExecutionLaunchDescriptor $descriptor, ExecutionProcessIdentity $expected, string $boot): ProcessLookupResult
    {
        if ($boot !== $expected->bootId) {
            return new ProcessLookupResult(ProcessLookupResult::MISMATCHED, null, null, ProcessScopeProof::uncertainFromLookup($descriptor, ProcessScopeProof::REASON_IDENTITY_CONFLICT));
        }

        // The expected identity is durable authenticated scope data.  Its
        // leader need not still exist, and a marker is not part of this proof.
        // If the leader is readable, use it only to reject PID reuse before
        // asking the attached handle to prove the complete scope.
        $base = $this->procPath . '/' . $expected->pid;
        $stat = @file_get_contents($base . '/stat');
        if ($stat !== false) {
            $fields = $this->statFields($stat);
            if ($fields === null || (int) $fields[1] !== $expected->pid) {
                return new ProcessLookupResult(ProcessLookupResult::BLOCKED, null, null, ProcessScopeProof::uncertainFromLookup($descriptor, ProcessScopeProof::REASON_RACE));
            }
            if ((int) $fields[5] < 1 || (int) $fields[6] < 1
                || (int) $fields[5] !== $expected->processGroup
                || (int) $fields[6] !== $expected->session
                || $fields[22] !== $expected->startTicks) {
                return new ProcessLookupResult(ProcessLookupResult::MISMATCHED, null, null, ProcessScopeProof::uncertainFromLookup($descriptor, ProcessScopeProof::REASON_IDENTITY_CONFLICT));
            }
        } elseif (is_dir($base)) {
            return new ProcessLookupResult(ProcessLookupResult::UNREADABLE, null, null, ProcessScopeProof::uncertainFromLookup($descriptor, ProcessScopeProof::REASON_UNREADABLE));
        }

        $identity = new ProcessIdentity($expected->pid, $expected->processGroup, $expected->session, $expected->startTicks, $expected->bootId, $descriptor);
        $handle = new AttachedProcessHandle($identity, $this->procPath);
        $proof = $handle->scopeProof();
        if ($proof->status === ProcessScopeProof::LIVE) {
            return new ProcessLookupResult(ProcessLookupResult::MATCH, $identity, $handle, ProcessScopeProof::liveFromLookup($descriptor, $identity));
        }
        if ($proof->status === ProcessScopeProof::CLOSED_ESRCH) {
            return new ProcessLookupResult(ProcessLookupResult::CLOSED, proof: ProcessScopeProof::closedEsrchFromLookup($descriptor, $identity));
        }
        if ($proof->status === ProcessScopeProof::CLOSED_TERMINAL) {
            return new ProcessLookupResult(ProcessLookupResult::CLOSED, proof: ProcessScopeProof::closedTerminalFromLookup($descriptor, $identity));
        }

        $status = match ($proof->reason) {
            ProcessScopeProof::REASON_UNREADABLE => ProcessLookupResult::UNREADABLE,
            ProcessScopeProof::REASON_IDENTITY_CONFLICT, ProcessScopeProof::REASON_GROUP_REUSE => ProcessLookupResult::MISMATCHED,
            ProcessScopeProof::REASON_UNSUPPORTED => ProcessLookupResult::UNSUPPORTED,
            default => ProcessLookupResult::BLOCKED,
        };
        return new ProcessLookupResult(
            $status,
            proof: ProcessScopeProof::uncertainFromLookup($descriptor, $proof->reason, $identity),
        );
    }

    /**
     * @param mixed $groupSessions
     * @return array<int,true>|null
     */
    private function validatedGroupSessions(mixed $groupSessions): ?array
    {
        if (!is_array($groupSessions)) {
            return null;
        }
        $validated = [];
        foreach ($groupSessions as $sessionId => $member) {
            if (!is_int($sessionId) || $member !== true) {
                return null;
            }
            $validated[$sessionId] = true;
        }
        return $validated;
    }

    /** @return array<int,string>|null */
    private function statFields(string $stat): ?array
    {
        // comm is parenthesized and may itself contain spaces or ')'.  The
        // suffix therefore has to be split only after its final delimiter.
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
        return preg_match('/^[0-9]+$/', $fields[22]) === 1 ? $fields : null;
    }
}
