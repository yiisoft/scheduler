<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Recovery;

use Yiisoft\Scheduler\Integration\Async\{ExecutionDispatchReservation, ExecutionLedgerQueryInterface, ExecutionLedgerRecord, ExecutionLedgerState, ExecutionRecoveryFence};
use Yiisoft\Scheduler\Integration\Async\Process\ProcessLookupResult;
use InvalidArgumentException;

use function in_array;

/** Source-pinned, observation-first recovery classification. */
final class RecoveryClassifier
{
    public function __construct(
        private readonly ExecutionLedgerQueryInterface $ledger,
        private readonly RecoveryProcessObserverInterface $processes,
        private readonly RecoveryAdmissionValidatorInterface $admissions,
    ) {}

    /**
     * Compatibility path for callers that do not have a planner-provided record set.
     *
     * @return list<RecoveryAction>
     */
    public function classify(string $sourceIdentity, int $nowUs, ?int $recordLimit = null): array
    {
        if ($recordLimit === null || $recordLimit < 1) {
            throw new InvalidArgumentException('A positive record limit is required for compatibility classification.');
        }

        /** @var list<ExecutionLedgerRecord> $records */
        $records = [];
        $cursor = null;
        $scanned = 0;
        while ($scanned < $recordLimit) {
            $limit = min(ExecutionLedgerQueryInterface::MAX_RECOVERY_PAGE_SIZE, $recordLimit - $scanned);
            if ($limit < 1) {
                break;
            }
            /** @var int<1, 256> $limit */
            if ($cursor === null) {
                $page = $this->ledger->recoveryPage($sourceIdentity, null, $limit);
            } else {
                /** @var non-empty-string $cursor */
                $page = $this->ledger->recoveryPage($sourceIdentity, $cursor, $limit);
            }
            foreach ($page->records as $record) {
                if ($scanned >= $recordLimit) {
                    break;
                }
                ++$scanned;
                $records[(string) $record->occurrence] = $record;
            }
            // Compatibility providers are not trusted to make progress. An
            // empty terminal page and a repeated cursor both end the bounded
            // scan rather than permitting an endless pagination loop.
            if ($scanned >= $recordLimit || $page->nextCursor === null) {
                break;
            }
            if ($page->nextCursor === $cursor) {
                break;
            }
            $cursor = $page->nextCursor;
        }

        return $this->classifyRecords(array_values($records), $sourceIdentity, $nowUs);
    }

    /**
     * @param list<ExecutionLedgerRecord> $records
     * @return list<RecoveryAction>
     */
    public function classifyRecords(array $records, string $sourceIdentity, int $nowUs): array
    {
        /** @var list<RecoveryAction> $actions */
        $actions = [];
        foreach ($records as $record) {
            if ($record->sourceIdentity !== $sourceIdentity) {
                continue;
            }
            $recoveryFence = $record->recoveryFence;
            $observations = $this->observe($record);
            $root = $this->root($observations);
            $preStartClosure = $record->launchObservation?->closure?->isConfirmed() === true
                && $record->startAnchorIdentity === null
                && $record->startObservation === null
                && $record->launchObservation?->startObservation === null
                && !in_array($record->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING], true);
            $live = array_filter($observations, static fn(RecoveryObservation $o): bool => $o->isLive() && !$preStartClosure);
            $uncertain = array_filter($observations, static fn(RecoveryObservation $o): bool => $o->isUncertain());
            $deadline = RecoveryDeadline::forRecord($record);
            $isAsync = ($record->taskSnapshot['task']['mode'] ?? null) === 'async';

            if ($record->isTerminal()) {
                $actions[] = $this->action(RecoveryAction::TERMINAL, $record, $root, $deadline, 'terminal ledger outcome', $recoveryFence, $observations);
                continue;
            }
            if ($recoveryFence === null || $recoveryFence->generation !== $record->fence->generation) {
                $actions[] = $this->action(RecoveryAction::BLOCKED, $record, $root, $deadline, 'source or recovery fence mismatch', $recoveryFence, $observations);
                continue;
            }
            if ($uncertain !== []) {
                $actions[] = $this->action(RecoveryAction::BLOCKED, $record, $root, $deadline, 'process identity lookup is uncertain', $recoveryFence, $observations);
                continue;
            }
            if ($live !== []) {
                $kind = $deadline !== null && $nowUs >= $deadline ? RecoveryAction::EXPIRED : RecoveryAction::WAIT;
                $reason = $kind === RecoveryAction::WAIT
                    ? ($root?->isLive() === true ? 'live worker observed; retain supervision' : 'live command scope observed; retain supervision')
                    : 'recovery window expired; observe live scope';
                $actions[] = $this->action($kind, $record, $root, $deadline, $reason, $recoveryFence, $observations);
                continue;
            }

            $aggregateClosed = $this->isAggregateClosed($record, $observations, $root, $deadline, $nowUs, $recoveryFence)
                && $this->admissions->accepts($record, $sourceIdentity);
            if ($aggregateClosed) {
                $actions[] = $this->action(RecoveryAction::AGGREGATE_REDELIVERY, $record, $root, $deadline, 'worker and command scopes are durably closed', $recoveryFence, $observations);
                continue;
            }

            // A descriptor may legitimately be absent before the first launch.
            // Do not manufacture a process lookup in that state: only the
            // durable launch facts and the admission validator can authorize
            // relaunch.
            $descriptorFreePreStart = $record->launchDescriptor === null
                && $record->launchObservation === null
                && $record->processIdentity === null
                && $record->startAnchorIdentity === null
                && $record->startObservation === null
                && $record->commandScope === null
                && in_array($record->state, [ExecutionLedgerState::LAUNCH_REQUIRED, ExecutionLedgerState::LAUNCH_REQUESTED], true)
                && ($record->dispatchReservation === null || $record->dispatchReservation->launchPhase === ExecutionDispatchReservation::PREPARED);
            if ($descriptorFreePreStart) {
                if (!$isAsync) {
                    $actions[] = $this->action(RecoveryAction::BLOCKED, $record, $root, $deadline, 'synchronous execution is not recoverable as a worker', $recoveryFence, $observations);
                } elseif ($this->admissions->accepts($record, $sourceIdentity)) {
                    $actions[] = $this->action(RecoveryAction::RELAUNCH, $record, $root, $deadline, 'no start-capable permission was consumed', $recoveryFence, $observations);
                } else {
                    $actions[] = $this->action(RecoveryAction::BLOCKED, $record, $root, $deadline, 'admitted revision or policy unavailable', $recoveryFence, $observations);
                }
                continue;
            }

            $absence = $this->absence($observations);
            $closureProof = $root !== null && (($root->lookup->status === ProcessLookupResult::CLOSED
                && $root->lookup->proof?->isClosed() === true)
                || $preStartClosure);
            $repair = $closureProof
                && in_array($record->state, [ExecutionLedgerState::LAUNCH_REQUESTED, ExecutionLedgerState::LAUNCH_AMBIGUOUS, ExecutionLedgerState::NEEDS_RESOLUTION], true)
                && $record->dispatchReservation !== null
                && in_array($record->dispatchReservation->launchPhase, [ExecutionDispatchReservation::STARTING, ExecutionDispatchReservation::AMBIGUOUS], true)
                && $record->startAnchorIdentity === null
                && $record->startObservation === null
                && $record->launchObservation?->startObservation === null;
            $preStartNoMatch = $root?->lookup->completedNoMatch() === true;
            if ($isAsync && $record->startObservation === null && ($preStartNoMatch || $repair)) {
                if ($this->admissions->accepts($record, $sourceIdentity)) {
                    $actions[] = $this->action(RecoveryAction::RELAUNCH, $record, $root, $deadline, 'completed absence proof before command start', $recoveryFence, $observations);
                } else {
                    $actions[] = $this->action(RecoveryAction::BLOCKED, $record, $root, $deadline, 'admitted revision or policy unavailable', $recoveryFence, $observations);
                }
                continue;
            }
            if ($absence) {
                $kind = $deadline === null || $nowUs < $deadline ? RecoveryAction::BLOCKED : RecoveryAction::EXPIRED_UNRESOLVED;
                $actions[] = $this->action($kind, $record, $root, $deadline, $kind === RecoveryAction::BLOCKED ? 'started execution is not live; awaiting recovery deadline' : 'started execution is not live; business outcome unresolved', $recoveryFence, $observations);
                continue;
            }
            $actions[] = $this->action(RecoveryAction::BLOCKED, $record, $root, $deadline, 'process identity lookup did not prove absence', $recoveryFence, $observations);
        }
        return $actions;
    }

    /** @return list<RecoveryObservation> */
    private function observe(ExecutionLedgerRecord $record): array
    {
        $result = [];
        if ($record->launchDescriptor !== null) {
            $lookup = $this->processes->lookup($record->launchDescriptor, $record->processIdentity);
            $result[] = new RecoveryObservation(RecoveryObservation::ROOT, $record->launchDescriptor, $lookup, $record->occurrence, $record->fence, $record->recoveryFence, $lookup->handle, expectedIdentity: $record->processIdentity);
        }
        if ($record->commandScope !== null) {
            $descriptor = $record->commandScope->descriptor;
            $expected = $record->commandScope->observation?->processIdentity;
            $lookup = $this->processes->lookup($descriptor, $expected);
            $result[] = new RecoveryObservation(RecoveryObservation::COMMAND, $descriptor, $lookup, $record->occurrence, $record->fence, $record->recoveryFence, $lookup->handle, expectedIdentity: $expected);
        }
        return $result;
    }

    /** @param list<RecoveryObservation> $observations */
    private function root(array $observations): ?RecoveryObservation
    {
        foreach ($observations as $observation) {
            if ($observation->role === RecoveryObservation::ROOT) {
                return $observation;
            }
        }
        return null;
    }

    /** @param list<RecoveryObservation> $observations */
    private function isAggregateClosed(
        ExecutionLedgerRecord $record,
        array $observations,
        ?RecoveryObservation $root,
        ?int $deadline,
        int $nowUs,
        ?ExecutionRecoveryFence $recoveryFence,
    ): bool {
        if (($record->taskSnapshot['task']['mode'] ?? null) !== 'async'
            || $record->outcome !== null
            || $root === null
            || $deadline === null
            || $nowUs >= $deadline
            || $recoveryFence === null
            || $recoveryFence->generation !== $record->fence->generation
            || $root->lookup->status !== ProcessLookupResult::CLOSED
            || !$root->lookup->proof?->isClosed()) {
            return false;
        }
        foreach ($observations as $observation) {
            if ($observation->lookup->status !== ProcessLookupResult::CLOSED || !$observation->lookup->proof?->isClosed()) {
                return false;
            }
        }
        return true;
    }

    /** @param list<RecoveryObservation> $observations */
    private function absence(array $observations): bool
    {
        if ($observations === []) {
            return false;
        }
        foreach ($observations as $observation) {
            if (!$observation->lookup->completedNoMatch() && !($observation->lookup->status === ProcessLookupResult::CLOSED && $observation->lookup->proof?->isClosed() === true)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<RecoveryObservation> $observations */
    private function action(string $kind, ExecutionLedgerRecord $record, ?RecoveryObservation $root, ?int $deadline, string $reason, ?ExecutionRecoveryFence $recoveryFence, array $observations): RecoveryAction
    {
        return new RecoveryAction($kind, $record, $record->fence, $recoveryFence, $root?->lookup, $deadline, $reason, $root?->verifiedHandle, $observations);
    }
}
