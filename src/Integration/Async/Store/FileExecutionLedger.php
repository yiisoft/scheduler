<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Store;

use InvalidArgumentException;
use Yiisoft\Scheduler\Integration\Async\{ExecutionAdmission,ExecutionClaimResult,ExecutionDispatchReservation,ExecutionDispatchResult,ExecutionFence,ExecutionGenerationResult,ExecutionLaunchObservation,ExecutionLedgerInterface,ExecutionLedgerQueryInterface,ExecutionLedgerRecord,ExecutionLedgerState,ExecutionOccurrenceKey,ExecutionOutcome,ExecutionRecoveryFence,ExecutionRecoveryResult,ExecutionRevocationResult,ExecutionTerminalResult};

use function in_array;
use function is_file;
use function is_int;
use function usort;
use function count;

use const LOCK_EX;
use const LOCK_UN;
use const JSON_THROW_ON_ERROR;

/** Local-filesystem ledger. It is atomic and cross-process safe on one filesystem, not distributed. */
final class FileExecutionLedger implements ExecutionLedgerInterface, ExecutionLedgerQueryInterface
{
    private readonly FileExecutionLedgerCodec $codec;
    private readonly FileExecutionLedgerIndexCodec $indexCodec;
    private readonly string $directory;
    private readonly FileExecutionLedgerSnapshotWriter $writer;

    public function __construct(string $directory, ?FileExecutionLedgerCodec $codec = null, ?FileExecutionLedgerSnapshotWriter $writer = null)
    {
        if ($directory === '' || !is_dir($directory) && !@mkdir($directory, 0770, true) || ($real = realpath($directory)) === false) {
            throw new InvalidArgumentException('Ledger directory is invalid.');
        }
        $this->directory = $real;
        $this->codec = $codec ?? new FileExecutionLedgerCodec();
        $this->indexCodec = new FileExecutionLedgerIndexCodec();
        $this->writer = $writer ?? new NativeFileExecutionLedgerSnapshotWriter();
        // Validate every durable component before any repair can replace legacy or
        // malformed state. Rebuildable indexes are not compatibility boundaries.
        $this->validateDurableState();
        $manifestPath = $this->manifestPath();
        $activePath = $this->indexPath('active');
        $ownershipPath = $this->indexPath('ownership');
        // Decode present indexes before any migration can replace them.
        /** @var list<array{taskKey:string,occurrenceId:string}>|null $decodedActive */
        $decodedActive = is_file($activePath) ? $this->readExecutionIndex('active') : null;
        /** @var list<array{taskKey:string,occurrenceId:string}>|null $decodedOwnership */
        $decodedOwnership = is_file($ownershipPath) ? $this->readExecutionIndex('ownership') : null;
        if (is_file($manifestPath)) {
            /** @var list<array{taskKey:string}> $manifest */
            $manifest = $this->readManifest();
            /** @var array<string,true> $manifestTasks */
            $manifestTasks = [];
            $repair = false;
            foreach ($manifest as $entry) {
                $manifestTasks[$this->markerIdentity($entry)] = true;
                $markerPath = $this->markerPath($entry);
                if (is_file($markerPath)) {
                    $this->validateHistoryMarker($entry, $markerPath);
                } else {
                    $repair = true;
                }
            }
            if ($decodedActive === null || $decodedOwnership === null) {
                $this->repairIndexes();
            } else {
                /** @var list<array{taskKey:string,occurrenceId:string}> $active */
                $active = $decodedActive;
                foreach ($active as $entry) {
                    $task = ['taskKey' => $entry['taskKey']];
                    $markerPath = $this->markerPath($task);
                    if (is_file($markerPath)) {
                        $this->validateHistoryMarker($task, $markerPath);
                    } else {
                        $repair = true;
                    }
                    if (!isset($manifestTasks[$this->markerIdentity($task)])) {
                        $repair = true;
                    }

                    $occurrence = new ExecutionOccurrenceKey($entry['taskKey'], $entry['occurrenceId']);
                    $record = $this->read($occurrence);
                    if ($record === null || !$this->isActive($record)) {
                        $repair = true;
                    }
                }
                foreach ($decodedOwnership as $entry) {
                    $occurrence = new ExecutionOccurrenceKey($entry['taskKey'], $entry['occurrenceId']);
                    try {
                        $record = $this->read($occurrence);
                    } catch (InvalidArgumentException) {
                        // Ownership is only a rebuildable lookup aid. Do not let an
                        // unrelated malformed closed snapshot break marker lookup.
                        continue;
                    }
                    if ($record === null || !$this->holdsOwnership($record)) {
                        $repair = true;
                    }
                }
                // Ownership completeness is checked against authoritative snapshots at startup,
                // but never by the per-transition index update path. If a snapshot cannot be
                // decoded, completeness cannot be established, so retain the existing index.
                /** @var list<array{taskKey:string,occurrenceId:string}> $expectedOwnership */
                $expectedOwnership = [];
                $ownershipCompletenessKnown = true;
                foreach (glob($this->directory . '/record-*.json') ?: [] as $path) {
                    try {
                        $record = $this->codec->decode((string) @file_get_contents($path));
                    } catch (InvalidArgumentException) {
                        $ownershipCompletenessKnown = false;
                        continue;
                    }
                    if ($this->holdsOwnership($record)) {
                        $expectedOwnership[] = $this->activeEntry($record);
                    }
                }
                if ($ownershipCompletenessKnown && $this->uniqueSorted($decodedOwnership) !== $this->uniqueSorted($expectedOwnership)) {
                    $repair = true;
                }
                if ($repair) {
                    $this->repairIndexes();
                }
            }
        } else {
            // The pre-index migration is the one intentional startup snapshot scan.
            $records = glob($this->directory . '/record-*.json') ?: [];
            if ($records !== []) {
                $this->repairIndexes();
            }
        }
    }

    public function admit(ExecutionLedgerRecord $record): ExecutionAdmission
    {
        // Encode and validate all existing durable state before taking any durable action.
        $encoded = $this->codec->encode($record);
        $this->validateDurableState();
        return $this->withLock($record->occurrence, function () use ($record, $encoded): ExecutionAdmission {
            $old = $this->read($record->occurrence);
            if ($old !== null) {
                return new ExecutionAdmission($old, false);
            }
            $this->writer->write($this->path($record->occurrence), $encoded);
            $this->changeIndexes(function (array &$active, array &$ownership, array &$history) use ($record): void {
                if ($this->isActive($record)) {
                    $active[] = $this->activeEntry($record);
                }
                if ($this->holdsOwnership($record)) {
                    $ownership[] = $this->activeEntry($record);
                }
                $history[] = $this->historyEntry($record);
            });
            return new ExecutionAdmission($record, true);
        });
    }

    public function observe(ExecutionOccurrenceKey $occurrence): ?ExecutionLedgerRecord
    {
        return $this->read($occurrence);
    }

    public function activeExecutions(): array
    {
        return $this->indexedExecutions('active', fn(ExecutionLedgerRecord $record): bool => $this->isActive($record));
    }

    public function ownershipHoldingExecutions(): array
    {
        return $this->indexedExecutions('ownership', fn(ExecutionLedgerRecord $record): bool => $this->holdsOwnership($record));
    }

    public function repairIndexes(): void
    {
        // Never let repair turn an old or malformed component into a new snapshot.
        $this->validateDurableState();
        $this->withIndexLock(function (): void {
            /** @var list<array{taskKey:string,occurrenceId:string}> $active */
            $active = [];
            /** @var list<array{taskKey:string,occurrenceId:string}> $ownership */
            $ownership = [];
            /** @var array<string,array{taskKey:string}> $entries */
            $entries = [];
            foreach (glob($this->directory . '/record-*.json') ?: [] as $path) {
                try {
                    $record = $this->codec->decode((string) @file_get_contents($path));
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException('Ledger snapshot is malformed during index repair.', 0, $e);
                }
                if ($this->isActive($record)) {
                    $active[] = $this->activeEntry($record);
                }
                if ($this->holdsOwnership($record)) {
                    $ownership[] = $this->activeEntry($record);
                }
                $history = $this->historyEntry($record);
                $entries[$this->markerIdentity($history)] = $history;
            }
            foreach (glob($this->directory . '/history-marker-*.json') ?: [] as $path) {
                try {
                    $marker = $this->indexCodec->decodeHistory((string) @file_get_contents($path));
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException('History marker is malformed during index repair.', 0, $e);
                }
                if (count($marker) !== 1) {
                    throw new InvalidArgumentException('History marker identity is invalid.');
                }
                $this->validateHistoryMarker($marker[0], $path);
                $entries[$this->markerIdentity($marker[0])] = $marker[0];
            }
            if (is_file($this->manifestPath())) {
                foreach ($this->readManifest() as $entry) {
                    $entries[$this->markerIdentity($entry)] = $entry;
                }
            }
            /** @var list<array{taskKey:string}> $entries */
            $entries = $this->uniqueSorted(array_values($entries));
            foreach ($entries as $entry) {
                if (!is_file($this->markerPath($entry))) {
                    $this->writeHistoryMarker($entry);
                }
            }
            $this->writer->write($this->manifestPath(), $this->indexCodec->encodeManifest($entries));
            /** @var list<array{taskKey:string,occurrenceId:string}> $active */
            $active = $this->uniqueSorted($active);
            $ownership = $this->uniqueSorted($ownership);
            $this->writer->write($this->indexPath('active'), $this->indexCodec->encodeActive($active));
            $this->writer->write($this->indexPath('ownership'), $this->indexCodec->encodeActive($ownership));
        });
    }

    public function hasTaskHistory(string $taskKey): bool
    {
        $entry = ['taskKey' => $taskKey];
        $path = $this->markerPath($entry);
        if (!is_file($path)) {
            return false;
        }
        $this->validateHistoryMarker($entry, $path);
        return true;
    }

    public function claim(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, int $at, ?int $claimLeaseDeadline): ExecutionClaimResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $fence, $at, $claimLeaseDeadline): ExecutionClaimResult {
            $r = $this->read($occurrence);
            if ($r === null || !$r->fence->equals($fence) || $r->isTerminal() || !in_array($r->state, [ExecutionLedgerState::LAUNCH_REQUIRED,ExecutionLedgerState::LAUNCH_REQUESTED], true) || ($claimLeaseDeadline !== null && $claimLeaseDeadline < $at)) {
                return ExecutionClaimResult::rejected('stale or unavailable execution');
            }$n = $this->copy($r, ExecutionLedgerState::CLAIMED, $at, null, claim: $claimLeaseDeadline);
            $this->write($n);
            return ExecutionClaimResult::accepted($n);
        });
    }

    public function reserveDispatch(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionDispatchReservation $reservation): ExecutionDispatchResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $fence, $reservation): ExecutionDispatchResult {
            $r = $this->read($occurrence);
            if ($r === null || !$r->fence->equals($fence)) {
                return ExecutionDispatchResult::stale();
            }if ($r->dispatchReservation !== null || $r->state !== ExecutionLedgerState::LAUNCH_REQUIRED) {
                return ExecutionDispatchResult::alreadyLaunched($r);
            }$n = $this->copy($r, ExecutionLedgerState::LAUNCH_REQUESTED, $reservation->reservedAt, null, dispatch: $reservation);
            $this->write($n);
            return ExecutionDispatchResult::accepted($n);
        });
    }

    public function redispatchSameGeneration(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionDispatchReservation $reservation, int $at): ExecutionDispatchResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $fence, $reservation, $at): ExecutionDispatchResult {
            $r = $this->read($occurrence);
            if ($r === null || !$r->fence->equals($fence)) {
                return ExecutionDispatchResult::stale();
            }if (in_array($r->state, [ExecutionLedgerState::CLAIMED,ExecutionLedgerState::RUNNING], true)) {
                return ExecutionDispatchResult::rejected('claimed execution cannot be redispatched');
            }if ($r->isTerminal() || $r->state === ExecutionLedgerState::REVOKED) {
                return ExecutionDispatchResult::stale('terminal or revoked execution');
            }if ($r->state !== ExecutionLedgerState::LAUNCH_REQUIRED && ($r->state !== ExecutionLedgerState::LAUNCH_REQUESTED || $r->dispatchReservation === null || $r->dispatchReservation->deadline > $at)) {
                return ExecutionDispatchResult::rejected('execution is not eligible for same-generation redispatch');
            }$n = $this->copy($r, ExecutionLedgerState::LAUNCH_REQUESTED, $at, null, dispatch: $reservation, launch: null, revoked: null);
            $this->write($n);
            return ExecutionDispatchResult::sameGenerationUnclaimed($n);
        });
    }

    public function observeLaunch(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionLaunchObservation $observation): ExecutionDispatchResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $fence, $observation): ExecutionDispatchResult {
            $r = $this->read($occurrence);
            if ($r === null) {
                return ExecutionDispatchResult::stale();
            }if ($r->isTerminal()) {
                // Terminal outcomes remain authoritative, but a same-generation
                // confirmed closure is still a fenced durable fact. Persist only
                // the closure, preserving the worker outcome and launch facts.
                if ($observation->closure?->isConfirmed() !== true) {
                    return ExecutionDispatchResult::accepted($r);
                }
                if (!$r->fence->equals($fence)) {
                    return ExecutionDispatchResult::stale();
                }
                $existing = $r->launchObservation;
                $closureObservation = $existing === null
                    ? $observation
                    : new ExecutionLaunchObservation($existing->pid, $existing->error, $existing->classification, $existing->observedAt, $observation->closure);
                $n = $this->copy($r, $r->state, $observation->observedAt, 'keep', launch: $closureObservation, claim: null);
                $this->write($n);
                return ExecutionDispatchResult::accepted($n);
            }if (!$r->fence->equals($fence)) {
                return ExecutionDispatchResult::stale();
            }if (!in_array($r->state, [ExecutionLedgerState::LAUNCH_REQUESTED,ExecutionLedgerState::LAUNCH_AMBIGUOUS], true) || $r->dispatchReservation === null) {
                return ExecutionDispatchResult::rejected('launch observation requires a dispatch reservation');
            }$n = $this->copy($r, ExecutionLedgerState::NEEDS_RESOLUTION, $observation->observedAt, null, launch: $observation);
            $this->write($n);
            return ExecutionDispatchResult::ambiguous($n);
        });
    }

    public function recover(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $fence, int $at): ExecutionRecoveryResult
    {
        $r = $this->read($occurrence);
        if ($r === null || $r->recoveryFence === null || !$r->recoveryFence->equals($fence)) {
            return ExecutionRecoveryResult::result(ExecutionRecoveryResult::STALE);
        }if ($r->isTerminal()) {
            return ExecutionRecoveryResult::terminal($r);
        }if ($r->state === ExecutionLedgerState::REVOKED) {
            return ExecutionRecoveryResult::result(ExecutionRecoveryResult::REVOKED, $r);
        }if (in_array($r->state, [ExecutionLedgerState::CLAIMED,ExecutionLedgerState::RUNNING], true)) {
            return $r->claimLeaseDeadline !== null && $r->claimLeaseDeadline <= $at ? ExecutionRecoveryResult::leaseExpiredObserved($r) : ExecutionRecoveryResult::result(ExecutionRecoveryResult::REJECTED, $r, 'claim lease is still live');
        }if ($r->state === ExecutionLedgerState::LAUNCH_REQUIRED || ($r->state === ExecutionLedgerState::LAUNCH_REQUESTED && $r->dispatchReservation !== null && $r->dispatchReservation->deadline <= $at)) {
            return ExecutionRecoveryResult::sameGenerationUnclaimed($r);
        }return ExecutionRecoveryResult::needsResolution($r);
    }

    public function revoke(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $fence, int $at): ExecutionRevocationResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $fence, $at): ExecutionRevocationResult {
            $r = $this->read($occurrence);
            if ($r === null || $r->recoveryFence === null || !$r->recoveryFence->equals($fence)) {
                return ExecutionRevocationResult::stale();
            }if ($r->isTerminal()) {
                return ExecutionRevocationResult::rejected('terminal outcome is authoritative');
            }if ($r->revokedAt !== null) {
                return ExecutionRevocationResult::alreadyRevoked($r);
            }if ($r->state !== ExecutionLedgerState::LAUNCH_REQUIRED && !$this->hasConfirmedClosure($r)) {
                return ExecutionRevocationResult::rejected('process closure is not confirmed');
            }$n = $this->copy($r, ExecutionLedgerState::REVOKED, $at, null, revoked: $at);
            $this->write($n);
            return ExecutionRevocationResult::accepted($n);
        });
    }

    public function allocateGeneration(ExecutionOccurrenceKey $occurrence, ExecutionRecoveryFence $fence, ExecutionFence $newFence, int $at): ExecutionGenerationResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $fence, $newFence, $at): ExecutionGenerationResult {
            $r = $this->read($occurrence);
            if ($r === null || $r->recoveryFence === null || !$r->recoveryFence->equals($fence)) {
                return ExecutionGenerationResult::stale();
            }if ($r->isTerminal()) {
                return ExecutionGenerationResult::rejected('terminal outcome is authoritative');
            }if ($r->revokedAt === null) {
                return ExecutionGenerationResult::requiresRevocation($r);
            }if (in_array($r->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING, ExecutionLedgerState::LAUNCH_AMBIGUOUS, ExecutionLedgerState::NEEDS_RESOLUTION], true) && !$this->hasConfirmedClosure($r)) {
                return ExecutionGenerationResult::rejected('process closure is not confirmed');
            }if ($newFence->generation <= $r->fence->generation) {
                return ExecutionGenerationResult::rejected('generation must increase strictly');
            }$n = $this->copy($r, ExecutionLedgerState::LAUNCH_REQUIRED, $at, null, fence: $newFence, attempt: $r->attempt + 1, dispatch: null, launch: null, revoked: null, claim: null);
            $this->write($n);
            return ExecutionGenerationResult::allocated($newFence, $n);
        });
    }

    public function recordOutcome(ExecutionOccurrenceKey $occurrence, ExecutionFence $fence, ExecutionOutcome $outcome, int $at): ExecutionTerminalResult
    {
        return $this->withLock($occurrence, function () use ($occurrence, $fence, $outcome, $at): ExecutionTerminalResult {
            $r = $this->read($occurrence);
            if ($r === null) {
                return ExecutionTerminalResult::missing();
            }if (!$r->fence->equals($fence) || $r->state === ExecutionLedgerState::REVOKED || $r->revokedAt !== null) {
                return ExecutionTerminalResult::staleFenced();
            }if ($r->isTerminal()) {
                return ExecutionTerminalResult::alreadyTerminal();
            }if (!in_array($r->state, [ExecutionLedgerState::CLAIMED,ExecutionLedgerState::RUNNING], true)) {
                return ExecutionTerminalResult::rejected('terminal outcome requires a claim or running transition');
            }if ($r->claimLeaseDeadline !== null && $at >= $r->claimLeaseDeadline) {
                return ExecutionTerminalResult::rejected('claim lease has expired');
            }$n = $this->copy($r, $outcome->status, $at, $outcome, claim: null);
            $this->write($n);
            return ExecutionTerminalResult::accepted($n);
        });
    }

    /** @return list<ExecutionLedgerRecord> */
    private function indexedExecutions(string $index, callable $predicate): array
    {
        if (!is_file($this->indexPath($index))) {
            return [];
        }
        $entries = $this->indexCodec->decodeActive((string) @file_get_contents($this->indexPath($index)));
        $result = [];
        $seen = [];
        foreach ($entries as $entry) {
            $occurrence = new ExecutionOccurrenceKey($entry['taskKey'], $entry['occurrenceId']);
            if (isset($seen[(string) $occurrence])) {
                continue;
            }
            $seen[(string) $occurrence] = true;
            $record = $this->read($occurrence);
            if ($record !== null && $predicate($record)) {
                $result[] = $record;
            }
        }
        return $result;
    }

    private function copy(ExecutionLedgerRecord $r, string $state, int $at, ExecutionOutcome|string|null $out, ExecutionDispatchReservation|string|null $dispatch = 'keep', ExecutionLaunchObservation|string|null $launch = 'keep', int|string|null $revoked = 'keep', ExecutionFence|string $fence = 'keep', int|string $attempt = 'keep', int|string|null $claim = 'keep'): ExecutionLedgerRecord
    {
        $outcomeValue = $out instanceof ExecutionOutcome || $out === null
            ? $out
            : ($out === 'keep' ? $r->outcome : throw new InvalidArgumentException('Invalid outcome.'));
        $attemptValue = $attempt === 'keep' ? $r->attempt : (is_int($attempt) ? $attempt : throw new InvalidArgumentException('Invalid attempt.'));
        $fenceValue = $fence === 'keep' ? $r->fence : ($fence instanceof ExecutionFence ? $fence : throw new InvalidArgumentException('Invalid fence.'));
        $dispatchValue = $dispatch === 'keep' ? $r->dispatchReservation : $dispatch;
        $launchValue = $launch === 'keep' ? $r->launchObservation : $launch;
        $revokedValue = $revoked === 'keep' ? $r->revokedAt : $revoked;
        $claimValue = $claim === 'keep' ? $r->claimLeaseDeadline : $claim;
        if (($dispatchValue !== null && !$dispatchValue instanceof ExecutionDispatchReservation) || ($launchValue !== null && !$launchValue instanceof ExecutionLaunchObservation) || ($revokedValue !== null && !is_int($revokedValue)) || ($claimValue !== null && !is_int($claimValue))) {
            throw new InvalidArgumentException('Invalid ledger transition.');
        }
        return new ExecutionLedgerRecord($r->occurrence, $r->correlation, $attemptValue, $fenceValue, $state, $r->launchIntent, $r->configurationFingerprint, $r->taskSnapshot, $r->createdAt, $at, $outcomeValue, $r->metadata, $dispatchValue, $launchValue, $r->recoveryFence, $revokedValue, $claimValue);
    }

    private function hasConfirmedClosure(ExecutionLedgerRecord $r): bool
    {
        return $r->launchObservation?->closure?->isConfirmed() ?? false;
    }

    private function validateDurableState(): void
    {
        foreach (glob($this->directory . '/record-*.json') ?: [] as $path) {
            try {
                $this->codec->decode((string) @file_get_contents($path));
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException('Ledger snapshot is malformed.', 0, $e);
            }
        }
        foreach (['active', 'ownership'] as $name) {
            if (is_file($this->indexPath($name))) {
                $this->readExecutionIndex($name);
            }
        }
        if (is_file($this->manifestPath())) {
            $this->readManifest();
        }
        foreach (glob($this->directory . '/history-marker-*.json') ?: [] as $path) {
            $json = @file_get_contents($path);
            if ($json === false) {
                throw new InvalidArgumentException('History marker cannot be read.');
            }
            try {
                $entries = $this->indexCodec->decodeHistory($json);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException('History marker is malformed.', 0, $e);
            }
            if (count($entries) !== 1) {
                throw new InvalidArgumentException('History marker identity is invalid.');
            }
            $this->validateHistoryMarker($entries[0], $path);
        }
    }

    private function path(ExecutionOccurrenceKey $o): string
    {
        return $this->directory . '/record-' . hash('sha256', (string) $o) . '.json';
    }

    private function indexPath(string $name): string
    {
        return $this->directory . '/' . $name . '-index.json';
    }

    private function manifestPath(): string
    {
        return $this->directory . '/task-manifest.json';
    }

    /** @return list<array{taskKey:string}> */
    private function readManifest(): array
    {
        $json = @file_get_contents($this->manifestPath());
        if ($json === false) {
            throw new InvalidArgumentException('Task manifest cannot be read.');
        }
        try {
            return $this->indexCodec->decodeManifest($json);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('Task manifest is malformed.', 0, $e);
        }
    }

    /** @param array{taskKey:string} $entry */
    private function markerPath(array $entry): string
    {
        return $this->directory . '/history-marker-' . $this->markerIdentity($entry) . '.json';
    }

    /** @param array{taskKey:string} $entry */
    private function markerIdentity(array $entry): string
    {
        return hash('sha256', $entry['taskKey']);
    }

    /** @param array{taskKey:string} $entry */
    private function writeHistoryMarker(array $entry): void
    {
        $this->writer->write($this->markerPath($entry), $this->indexCodec->encodeHistory([$entry]));
    }

    /** @param array{taskKey:string} $entry */
    private function validateHistoryMarker(array $entry, string $path): void
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new InvalidArgumentException('History marker cannot be read.');
        }
        try {
            $marker = $this->indexCodec->decodeHistory($json);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('History marker is malformed.', 0, $e);
        }
        if (count($marker) !== 1 || $marker[0] !== $entry || $this->markerPath($marker[0]) !== $path || $json !== $this->indexCodec->encodeHistory([$entry])) {
            throw new InvalidArgumentException('History marker identity is invalid.');
        }
    }

    private function isActive(ExecutionLedgerRecord $record): bool
    {
        return !$record->isTerminal() && $record->state !== ExecutionLedgerState::REVOKED;
    }

    private function holdsOwnership(ExecutionLedgerRecord $record): bool
    {
        return $record->state !== ExecutionLedgerState::REVOKED
            && (!$record->isTerminal() || !$this->hasConfirmedClosure($record));
    }

    /** @return array{taskKey:string,occurrenceId:string} */
    private function activeEntry(ExecutionLedgerRecord $record): array
    {
        return ['taskKey' => $record->occurrence->taskKey, 'occurrenceId' => $record->occurrence->occurrenceId];
    }

    /** @return array{taskKey:string} */
    private function historyEntry(ExecutionLedgerRecord $record): array
    {
        return ['taskKey' => $record->occurrence->taskKey];
    }

    /**
     * @param callable(
     *     list<array{taskKey:string,occurrenceId:string}>&,
     *     list<array{taskKey:string,occurrenceId:string}>&,
     *     list<array{taskKey:string}>&
     * ): void $change
     */
    private function changeIndexes(callable $change): void
    {
        $this->withIndexLock(function () use ($change): void {
            $active = $this->readExecutionIndex('active');
            $ownership = $this->readExecutionIndex('ownership');
            $history = [];
            $manifest = is_file($this->manifestPath()) ? $this->readManifest() : [];
            $change($active, $ownership, $history);
            /** @var list<array{taskKey:string}> $historyEntries */
            $historyEntries = $this->uniqueSorted($history);
            foreach ($historyEntries as $entry) {
                $path = $this->markerPath($entry);
                if (is_file($path)) {
                    $this->validateHistoryMarker($entry, $path);
                } else {
                    $this->writeHistoryMarker($entry);
                }
                $manifest[] = $entry;
            }
            /** @var list<array{taskKey:string}> $manifest */
            $manifest = $this->uniqueSorted($manifest);
            $this->writer->write($this->manifestPath(), $this->indexCodec->encodeManifest($manifest));
            /** @var list<array{taskKey:string,occurrenceId:string}> $active */
            $active = $this->uniqueSorted($active);
            $ownership = $this->uniqueSorted($ownership);
            $this->writer->write($this->indexPath('active'), $this->indexCodec->encodeActive($active));
            $this->writer->write($this->indexPath('ownership'), $this->indexCodec->encodeActive($ownership));
        });
    }

    /**
     * @return list<array{taskKey:string,occurrenceId:string}>
     */
    private function readExecutionIndex(string $name): array
    {
        $path = $this->indexPath($name);
        if (!is_file($path)) {
            return [];
        }
        return $this->indexCodec->decodeActive((string) @file_get_contents($path));
    }

    /** @param callable(): void $fn */
    private function withIndexLock(callable $fn): void
    {
        $handle = @fopen($this->directory . '/index.lock', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new InvalidArgumentException('Ledger index lock cannot be acquired.');
        }
        try {
            $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @template T of array<string,string>
     * @param list<T> $entries
     * @return list<T>
     */
    private function uniqueSorted(array $entries): array
    {
        $unique = [];
        foreach ($entries as $entry) {
            $unique[json_encode($entry, JSON_THROW_ON_ERROR)] = $entry;
        }
        /** @var list<T> $result */
        $result = array_values($unique);
        usort($result, static fn(array $left, array $right): int => strcmp(
            implode("\0", array_map(static fn(mixed $value): string => (string) $value, $left)),
            implode("\0", array_map(static fn(mixed $value): string => (string) $value, $right)),
        ));
        return $result;
    }

    private function read(ExecutionOccurrenceKey $o): ?ExecutionLedgerRecord
    {
        $p = $this->path($o);
        if (!is_file($p)) {
            return null;
        }$s = @file_get_contents($p);
        if ($s === false) {
            throw new InvalidArgumentException('Ledger snapshot cannot be read.');
        }$r = $this->codec->decode($s);
        if (!$r->occurrence->equals($o)) {
            throw new InvalidArgumentException('Ledger identity mismatch.');
        }return $r;
    }

    private function write(ExecutionLedgerRecord $r): void
    {
        $encoded = $this->codec->encode($r);
        $this->writer->write($this->path($r->occurrence), $encoded);
        /**
         * @param list<array{taskKey:string,occurrenceId:string}> $active
         * @param list<array{taskKey:string,occurrenceId:string}> $ownership
         * @param list<array{taskKey:string}> $history
         * @param-out list<array{taskKey:string,occurrenceId:string}> $active
         * @param-out list<array{taskKey:string,occurrenceId:string}> $ownership
         * @param-out list<array{taskKey:string}> $history
         */
        /** @psalm-suppress ReferenceConstraintViolation Psalm cannot express this mutable tuple callback precisely. */
        $this->changeIndexes(function (array &$active, array &$ownership, array &$history) use ($r): void {
            $history[] = $this->historyEntry($r);
            $entry = $this->activeEntry($r);
            $same = static fn(array $item): bool => $item['occurrenceId'] !== $entry['occurrenceId'] || $item['taskKey'] !== $entry['taskKey'];
            $active = array_values(array_filter($active, $same));
            $ownership = array_values(array_filter($ownership, $same));
            if ($this->isActive($r)) {
                $active[] = $entry;
            }
            if ($this->holdsOwnership($r)) {
                $ownership[] = $entry;
            }
        });
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withLock(ExecutionOccurrenceKey $o, callable $fn): mixed
    {
        $p = $this->path($o) . '.lock';
        $h = @fopen($p, 'c+');
        if ($h === false || !flock($h, LOCK_EX)) {
            throw new InvalidArgumentException('Ledger lock cannot be acquired.');
        }try {
            return $fn();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }
}
