<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;
use Yiisoft\Scheduler\Config\PreparedConfiguration;
use Yiisoft\Scheduler\ScalarMetadata;
use Yiisoft\Scheduler\Integration\Async\Recovery\RecoveryDeadline;

use function array_key_exists;
use function count;
use function is_array;
use function is_finite;
use function is_string;
use function in_array;
use function strlen;
use function str_contains;

/** Immutable snapshot of the single durable execution-ledger record. */
final class ExecutionLedgerRecord
{
    public function __construct(
        public readonly ExecutionOccurrenceKey $occurrence,
        public readonly ExecutionIdentity $correlation,
        public readonly int $attempt,
        public readonly ExecutionFence $fence,
        public readonly string $state,
        public readonly string $configurationFingerprint,
        public readonly array $taskSnapshot = [],
        public readonly int $createdAt = 0,
        public readonly int $updatedAt = 0,
        public readonly ?ExecutionOutcome $outcome = null,
        public readonly array $metadata = [],
        public readonly ?ExecutionDispatchReservation $dispatchReservation = null,
        public readonly ?ExecutionLaunchObservation $launchObservation = null,
        public readonly ?ExecutionRecoveryFence $recoveryFence = null,
        public readonly ?int $revokedAt = null,
        /** Deadline of the worker claim lease, when a claim is leased. */
        public readonly ?int $claimLeaseDeadline = null,
        /** Canonical admitted source facts; never inferred as start facts. */
        public readonly ?string $sourceIdentity = null,
        public readonly ?string $sourceRevision = null,
        public readonly ?ExecutionLaunchDescriptor $launchDescriptor = null,
        public readonly ?ExecutionProcessIdentity $processIdentity = null,
        /** Durable identity witnessing the original authoritative start anchor. */
        public readonly ?ExecutionProcessIdentity $startAnchorIdentity = null,
        /** Original authoritative start anchor; preserved across attempts. */
        public readonly ?ExecutionStartObservation $startObservation = null,
        /** Current attempt timing; never used to compute recovery expiry. */
        public readonly ?ExecutionStartObservation $attemptStartObservation = null,
        public readonly ?float $recoveryAge = null,
        public readonly ?int $recoveryDeadline = null,
        public readonly ?string $recoveryDisposition = null,
        public readonly ?ExecutionCommandScope $commandScope = null,
        /** Complete diagnostic snapshots of generations retired by rotation. */
        public readonly array $retiredGenerations = [],
    ) {
        ExecutionLedgerState::assertValid($state);
        if ($attempt < 1 || $createdAt < 0 || $updatedAt < $createdAt) {
            throw new InvalidArgumentException('Execution ledger facts are invalid.');
        }
        if ($occurrence->taskKey !== $correlation->taskKey || $occurrence->occurrenceId !== $correlation->occurrenceId) {
            throw new InvalidArgumentException('Execution ledger identity does not match its correlation.');
        }
        if ($recoveryFence !== null && $recoveryFence->generation !== $fence->generation) {
            throw new InvalidArgumentException('Execution recovery fence generation is invalid.');
        }
        if (!self::launchDescriptorMatches($launchDescriptor, $occurrence, $correlation, $fence)) {
            throw new InvalidArgumentException('Execution launch descriptor binding is invalid.');
        }
        if ($commandScope !== null && ($launchDescriptor === null || $launchDescriptor->role !== ExecutionLaunchDescriptor::ROLE_WORKER
            || !($commandScope->descriptor->rootDescriptor instanceof ExecutionLaunchDescriptor)
            || !$commandScope->descriptor->rootDescriptor->equals($launchDescriptor)
            || $commandScope->descriptor->occurrence === null || !$commandScope->descriptor->occurrence->equals($occurrence)
            || $commandScope->descriptor->generation !== $fence->generation)) {
            throw new InvalidArgumentException('Execution command scope binding is invalid.');
        }
        if ($configurationFingerprint === '' || strlen($configurationFingerprint) > 4096 || !self::isPortableConfigurationFingerprint($configurationFingerprint)) {
            throw new InvalidArgumentException('Execution ledger configuration fingerprint is invalid.');
        }
        PreparedConfiguration::assertTaskPolicyProjection($taskSnapshot);
        ScalarMetadata::assertAggregateSupported($taskSnapshot, $metadata);
        foreach ($retiredGenerations as $retired) {
            if (!$retired instanceof ExecutionRetiredGeneration) {
                throw new InvalidArgumentException('Retired generation history is invalid.');
            }
        }
        if (array_key_exists('sourceId', $metadata) && $metadata['sourceId'] !== $sourceIdentity
            || array_key_exists('sourceRevision', $metadata) && $metadata['sourceRevision'] !== $sourceRevision) {
            throw new InvalidArgumentException('Execution metadata source facts do not match the canonical source.');
        }
        if (ExecutionLedgerState::isTerminal($state) !== ($outcome !== null)) {
            throw new InvalidArgumentException('Terminal state and outcome must agree.');
        }
        if ($outcome !== null && $outcome->status !== $state) {
            throw new InvalidArgumentException('Terminal state does not match outcome.');
        }
        if ($revokedAt !== null && $revokedAt < $createdAt) {
            throw new InvalidArgumentException('Revocation timestamp is invalid.');
        }
        $snapshotSource = $taskSnapshot['source'] ?? null;
        if ($sourceIdentity === null || $sourceRevision === null) {
            throw new InvalidArgumentException('Execution source facts are required.');
        }
        self::assertSourceFact($sourceIdentity, 'identity');
        self::assertSourceFact($sourceRevision, 'revision');
        {
            if (!is_array($snapshotSource) || count($snapshotSource) !== 2
                || !array_key_exists(0, $snapshotSource) || !array_key_exists(1, $snapshotSource)
                || !is_string($snapshotSource[0]) || !is_string($snapshotSource[1])) {
                throw new InvalidArgumentException('Execution task policy source facts are invalid.');
            }
            self::assertSourceFact($snapshotSource[0], 'policy identity');
            self::assertSourceFact($snapshotSource[1], 'policy revision');
            if ($sourceIdentity !== $snapshotSource[0] || $sourceRevision !== $snapshotSource[1]) {
                throw new InvalidArgumentException('Execution source facts do not match the task policy.');
            }
        }
        if ($processIdentity === null && $attemptStartObservation !== null) {
            throw new InvalidArgumentException('Attempt start facts require complete process identity.');
        }
        if (($startAnchorIdentity === null) !== ($startObservation === null)) {
            throw new InvalidArgumentException('Start anchor identity and observation must be paired.');
        }
        self::assertLaunchObservationConsistency(
            $launchObservation,
            $processIdentity,
            $attemptStartObservation,
            $startAnchorIdentity,
            $startObservation,
        );
        self::assertDispatchSemantics(
            $state,
            $dispatchReservation,
            $launchObservation,
            $processIdentity,
            $attemptStartObservation,
            $startAnchorIdentity,
            $startObservation,
            $recoveryDeadline,
            $launchDescriptor,
        );
        if ($recoveryAge !== null && (!is_finite($recoveryAge) || $recoveryAge <= 0)) {
            throw new InvalidArgumentException('Execution recovery age is invalid.');
        }
        if (($recoveryAge !== null || $recoveryDeadline !== null) && $startAnchorIdentity === null) {
            throw new InvalidArgumentException('Execution recovery facts require an explicit start.');
        }
        if ($recoveryDeadline !== null && $recoveryAge === null) {
            throw new InvalidArgumentException('Execution recovery deadline requires recovery age.');
        }
        if ($startAnchorIdentity !== null && ($recoveryAge === null || $recoveryDeadline === null)) {
            throw new InvalidArgumentException('Started recovery facts are incomplete.');
        }
        if ($startAnchorIdentity !== null && $recoveryAge !== ($this->taskSnapshot['task']['recoveryMaxAge'] ?? null)) {
            throw new InvalidArgumentException('Execution recovery age does not match the recovery policy.');
        }
        if ($recoveryDeadline !== null && $recoveryDeadline < $createdAt) {
            throw new InvalidArgumentException('Execution recovery deadline is invalid.');
        }
        if ($startAnchorIdentity !== null) {
            if ($startObservation === null) {
                throw new InvalidArgumentException('Started recovery facts are incomplete.');
            }
            if ($recoveryDeadline !== RecoveryDeadline::forStart($this, $startObservation)) {
                throw new InvalidArgumentException('Execution recovery deadline does not match the recovery policy.');
            }
        }
        if ($recoveryDisposition !== null && $recoveryDisposition !== ExecutionLedgerState::RECOVERY_EXPIRED) {
            throw new InvalidArgumentException('Execution recovery disposition is invalid.');
        }
        if ($claimLeaseDeadline !== null && $claimLeaseDeadline < $createdAt) {
            throw new InvalidArgumentException('Claim lease deadline is invalid.');
        }
        if (($state === ExecutionLedgerState::REVOKED) !== ($revokedAt !== null)) {
            throw new InvalidArgumentException('Revocation state and timestamp must agree.');
        }
        $this->assertValid();
    }

    /**
     * Check the binding required for a descriptor persisted in a root ledger
     * record. Keeping this projection here makes construction and transition
     * paths use the same rule as the filesystem ledger admission boundary.
     */
    public static function launchDescriptorMatches(
        ?ExecutionLaunchDescriptor $descriptor,
        ExecutionOccurrenceKey $occurrence,
        ExecutionIdentity $correlation,
        ExecutionFence $fence,
    ): bool {
        return $descriptor === null
            || ($descriptor->role === ExecutionLaunchDescriptor::ROLE_WORKER
                && $descriptor->scopeKind === ExecutionLaunchDescriptor::SCOPE_KIND
                && $descriptor->occurrence !== null
                && $descriptor->occurrence->equals($occurrence)
                && $descriptor->executionId === $correlation->executionId
                && $descriptor->generation === $fence->generation);
    }

    /** Validate invariants shared by durable encoding and decoding. */
    public function assertValid(): void
    {
        if (ExecutionLedgerState::isTerminal($this->state) !== ($this->outcome !== null)
            || ($this->outcome !== null && $this->outcome->status !== $this->state)
            || ($this->sourceIdentity === null) !== ($this->sourceRevision === null)
            || !self::launchDescriptorMatches($this->launchDescriptor, $this->occurrence, $this->correlation, $this->fence)) {
            throw new InvalidArgumentException('Execution ledger transition invariants are invalid.');
        }
    }

    /**
     * Return an immutable snapshot with only the explicitly supplied facts changed.
     * The named arguments are intentional: ledger transitions must not depend on
     * the order of this record's durable facts.
     */
    public function transition(
        ?string $state = null,
        ExecutionOutcome|string|null $outcome = 'keep',
        ExecutionDispatchReservation|string|null $dispatchReservation = 'keep',
        ExecutionLaunchObservation|string|null $launchObservation = 'keep',
        int|string|null $revokedAt = 'keep',
        int|string|null $claimLeaseDeadline = 'keep',
        ExecutionFence|string $fence = 'keep',
        int|string $attempt = 'keep',
        ExecutionRecoveryFence|string|null $recoveryFence = 'keep',
        ExecutionLaunchDescriptor|string|null $launchDescriptor = 'keep',
        ExecutionProcessIdentity|string|null $processIdentity = 'keep',
        ExecutionProcessIdentity|string|null $startAnchorIdentity = 'keep',
        ExecutionStartObservation|string|null $startObservation = 'keep',
        ExecutionStartObservation|string|null $attemptStartObservation = 'keep',
        ?int $updatedAt = null,
        float|string|null $recoveryAge = 'keep',
        int|string|null $recoveryDeadline = 'keep',
        ?string $recoveryDisposition = 'keep',
        ExecutionCommandScope|string|null $commandScope = 'keep',
        array|string $retiredGenerations = 'keep',
    ): self {
        foreach ([$outcome, $dispatchReservation, $launchObservation, $revokedAt, $claimLeaseDeadline, $fence, $attempt, $recoveryFence, $launchDescriptor, $processIdentity, $startAnchorIdentity, $startObservation, $attemptStartObservation, $recoveryAge, $recoveryDeadline, $commandScope, $retiredGenerations] as $sentinel) {
            if (is_string($sentinel) && $sentinel !== 'keep') {
                throw new InvalidArgumentException('Execution ledger transition sentinel is invalid.');
            }
        }
        // A string reaching any sentinel slot has been narrowed to the only
        // permitted sentinel, which keeps the immutable snapshot constructor typed.
        if (is_string($outcome)) {
            $outcome = 'keep';
        }
        if (is_string($dispatchReservation)) {
            $dispatchReservation = 'keep';
        }
        if (is_string($launchObservation)) {
            $launchObservation = 'keep';
        }
        if (is_string($revokedAt)) {
            $revokedAt = 'keep';
        }
        if (is_string($claimLeaseDeadline)) {
            $claimLeaseDeadline = 'keep';
        }
        if (is_string($fence)) {
            $fence = 'keep';
        }
        if (is_string($attempt)) {
            $attempt = 'keep';
        }
        if (is_string($recoveryFence)) {
            $recoveryFence = 'keep';
        }
        if (is_string($launchDescriptor)) {
            $launchDescriptor = 'keep';
        }
        if (is_string($processIdentity)) {
            $processIdentity = 'keep';
        }
        if (is_string($startAnchorIdentity)) {
            $startAnchorIdentity = 'keep';
        }
        if (is_string($startObservation)) {
            $startObservation = 'keep';
        }
        if (is_string($attemptStartObservation)) {
            $attemptStartObservation = 'keep';
        }
        if (is_string($recoveryAge)) {
            $recoveryAge = 'keep';
        }
        if (is_string($recoveryDeadline)) {
            $recoveryDeadline = 'keep';
        }
        if (is_string($commandScope)) {
            $commandScope = 'keep';
        }
        if (is_string($retiredGenerations)) {
            $retiredGenerations = 'keep';
        }
        $nextState = $state ?? $this->state;
        $nextFence = $fence === 'keep' ? $this->fence : $fence;
        if ($nextState !== $this->state) {
            if ($this->isTerminal()) {
                throw new InvalidArgumentException('Terminal lifecycle state is immutable.');
            }
            if (ExecutionLedgerState::isTerminal($nextState)) {
                if ($this->state === ExecutionLedgerState::REVOKED
                    || !in_array($this->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING, ExecutionLedgerState::NEEDS_RESOLUTION, ExecutionLedgerState::RECOVERY_EXPIRED], true)
                    || (in_array($this->state, [ExecutionLedgerState::NEEDS_RESOLUTION, ExecutionLedgerState::RECOVERY_EXPIRED], true) && $this->claimLeaseDeadline === null)) {
                    throw new InvalidArgumentException('Only a claimed execution may become terminal.');
                }
            }
            $rank = static function (string $value): int {
                return match ($value) {
                    ExecutionLedgerState::LAUNCH_REQUIRED => 0,
                    ExecutionLedgerState::LAUNCH_REQUESTED => 1,
                    ExecutionLedgerState::LAUNCH_AMBIGUOUS => 2,
                    ExecutionLedgerState::NEEDS_RESOLUTION => 3,
                    ExecutionLedgerState::CLAIMED => 4,
                    ExecutionLedgerState::RUNNING => 5,
                    ExecutionLedgerState::RECOVERY_EXPIRED => 6,
                    ExecutionLedgerState::REVOKED => 7,
                    default => 8,
                };
            };
            $observationProjection = in_array($nextState, [ExecutionLedgerState::NEEDS_RESOLUTION], true)
                && in_array($this->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING], true)
                && $launchObservation !== 'keep';
            $freshGeneration = $this->state === ExecutionLedgerState::REVOKED
                && $nextState === ExecutionLedgerState::LAUNCH_REQUIRED
                && $nextFence->generation > $this->fence->generation;
            $claimPromotion = $nextState === ExecutionLedgerState::CLAIMED
                && in_array($this->state, [ExecutionLedgerState::LAUNCH_REQUESTED, ExecutionLedgerState::LAUNCH_AMBIGUOUS, ExecutionLedgerState::NEEDS_RESOLUTION], true);
            $provenAbsenceRepair = $nextState === ExecutionLedgerState::LAUNCH_REQUESTED
                && in_array($this->state, [ExecutionLedgerState::LAUNCH_AMBIGUOUS, ExecutionLedgerState::NEEDS_RESOLUTION], true)
                && $dispatchReservation instanceof ExecutionDispatchReservation
                && $launchObservation === null
                && $fence === 'keep'
                && $attempt === 'keep'
                && $recoveryFence === 'keep';
            if (!$observationProjection && !$freshGeneration && !$claimPromotion && !$provenAbsenceRepair && $rank($nextState) < $rank($this->state)) {
                throw new InvalidArgumentException('Execution lifecycle transition is not monotonic.');
            }
        }
        if (($nextState === ExecutionLedgerState::NEEDS_RESOLUTION || $nextState === ExecutionLedgerState::RECOVERY_EXPIRED)
            && $claimLeaseDeadline !== 'keep' && $claimLeaseDeadline !== null
            && (!in_array($this->state, [ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING], true)
                && !($this->claimLeaseDeadline !== null && in_array($this->state, [ExecutionLedgerState::NEEDS_RESOLUTION, ExecutionLedgerState::RECOVERY_EXPIRED], true)))) {
            throw new InvalidArgumentException('Unclaimed execution cannot acquire claim provenance.');
        }
        $nextAnchorIdentity = $startAnchorIdentity === 'keep' ? $this->startAnchorIdentity : $startAnchorIdentity;
        $nextStart = $startObservation === 'keep' ? $this->startObservation : $startObservation;
        $nextDeadline = $recoveryDeadline === 'keep' ? $this->recoveryDeadline : $recoveryDeadline;
        if ($this->startAnchorIdentity !== null && (!$this->sameProcessIdentity($nextAnchorIdentity, $this->startAnchorIdentity) || !$this->sameStartObservation($nextStart, $this->startObservation) || $nextDeadline !== $this->recoveryDeadline)) {
            throw new InvalidArgumentException('Original recovery anchor is immutable.');
        }
        if ($this->startAnchorIdentity === null && ($nextAnchorIdentity !== null || $nextStart !== null) && ($nextAnchorIdentity === null || $nextStart === null)) {
            throw new InvalidArgumentException('Start anchor identity and observation must be paired.');
        }
        return new self(
            occurrence: $this->occurrence,
            correlation: $this->correlation,
            attempt: $attempt === 'keep' ? $this->attempt : $attempt,
            fence: $nextFence,
            state: $nextState,
            configurationFingerprint: $this->configurationFingerprint,
            taskSnapshot: $this->taskSnapshot,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt ?? $this->updatedAt,
            outcome: $outcome === 'keep' ? $this->outcome : $outcome,
            metadata: $this->metadata,
            dispatchReservation: $dispatchReservation === 'keep' ? $this->dispatchReservation : $dispatchReservation,
            launchObservation: $launchObservation === 'keep' ? $this->launchObservation : $launchObservation,
            recoveryFence: $recoveryFence === 'keep' ? $this->recoveryFence : $recoveryFence,
            revokedAt: $revokedAt === 'keep' ? $this->revokedAt : $revokedAt,
            claimLeaseDeadline: $claimLeaseDeadline === 'keep' ? $this->claimLeaseDeadline : $claimLeaseDeadline,
            sourceIdentity: $this->sourceIdentity,
            sourceRevision: $this->sourceRevision,
            launchDescriptor: $launchDescriptor === 'keep' ? $this->launchDescriptor : $launchDescriptor,
            processIdentity: $processIdentity === 'keep' ? $this->processIdentity : $processIdentity,
            startAnchorIdentity: $nextAnchorIdentity,
            startObservation: $nextStart,
            attemptStartObservation: $attemptStartObservation === 'keep' ? $this->attemptStartObservation : $attemptStartObservation,
            recoveryAge: $recoveryAge === 'keep' ? $this->recoveryAge : $recoveryAge,
            recoveryDeadline: $recoveryDeadline === 'keep' ? $this->recoveryDeadline : $recoveryDeadline,
            recoveryDisposition: $recoveryDisposition === 'keep' ? $this->recoveryDisposition : $recoveryDisposition,
            commandScope: $commandScope === 'keep' ? $this->commandScope : $commandScope,
            retiredGenerations: $retiredGenerations === 'keep' ? $this->retiredGenerations : $retiredGenerations,
        );
    }

    public function isTerminal(): bool
    {
        return ExecutionLedgerState::isTerminal($this->state);
    }

    private static function assertDispatchSemantics(
        string $state,
        ?ExecutionDispatchReservation $reservation,
        ?ExecutionLaunchObservation $observation,
        ?ExecutionProcessIdentity $processIdentity,
        ?ExecutionStartObservation $attemptStart,
        ?ExecutionProcessIdentity $anchorIdentity,
        ?ExecutionStartObservation $anchorStart,
        ?int $recoveryDeadline,
        ?ExecutionLaunchDescriptor $descriptor,
    ): void {
        if ($reservation === null) {
            return;
        }
        if ($reservation->launchPhase === ExecutionDispatchReservation::PREPARED
            && ($attemptStart !== null
                || ($observation !== null && $observation->startObservation !== null))) {
            throw new InvalidArgumentException('Prepared dispatch reservation has published launch facts.');
        }
        // A prepared record may retain the original recovery anchor (and a
        // partial/closure-only observation) while no current attempt facts exist.
        // Once current process/start facts are present, observations are fenced
        // by the phase transitions in the ledger.
        if ($reservation->launchPhase === ExecutionDispatchReservation::PREPARED
            && $state === ExecutionLedgerState::LAUNCH_AMBIGUOUS) {
            throw new InvalidArgumentException('Prepared dispatch reservation has an impossible state.');
        }
        if ($reservation->launchPhase === ExecutionDispatchReservation::STARTING
            && ($state !== ExecutionLedgerState::LAUNCH_REQUESTED
                || $processIdentity !== null || $attemptStart !== null
                || ($observation !== null && ($observation->processIdentity !== null || $observation->startObservation !== null)))) {
            throw new InvalidArgumentException('Starting dispatch reservation has published launch facts.');
        }
        if ($reservation->launchPhase === ExecutionDispatchReservation::OBSERVED
            && ($observation === null || $processIdentity === null || $attemptStart === null
                || $anchorIdentity === null || $anchorStart === null || $recoveryDeadline === null)) {
            throw new InvalidArgumentException('Observed dispatch reservation requires complete launch facts.');
        }
        if ($reservation->launchPhase === ExecutionDispatchReservation::OBSERVED
            && !in_array($state, [ExecutionLedgerState::NEEDS_RESOLUTION, ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING, ExecutionLedgerState::RECOVERY_EXPIRED, ExecutionLedgerState::REVOKED], true)
            && !ExecutionLedgerState::isTerminal($state)) {
            throw new InvalidArgumentException('Observed dispatch reservation has an impossible state.');
        }
        if ($reservation->launchPhase === ExecutionDispatchReservation::AMBIGUOUS
            && !in_array($state, [ExecutionLedgerState::LAUNCH_AMBIGUOUS, ExecutionLedgerState::NEEDS_RESOLUTION, ExecutionLedgerState::CLAIMED, ExecutionLedgerState::RUNNING, ExecutionLedgerState::REVOKED], true)
            && !ExecutionLedgerState::isTerminal($state)
            && $state !== ExecutionLedgerState::RECOVERY_EXPIRED) {
            throw new InvalidArgumentException('Ambiguous dispatch reservation has an impossible state.');
        }
    }

    private static function assertLaunchObservationConsistency(
        ?ExecutionLaunchObservation $observation,
        ?ExecutionProcessIdentity $processIdentity,
        ?ExecutionStartObservation $attemptStartObservation,
        ?ExecutionProcessIdentity $startAnchorIdentity,
        ?ExecutionStartObservation $startObservation,
    ): void {
        if ($observation === null) {
            return;
        }

        // An identity-only observation is deliberately allowed to remain
        // independent while timing is incomplete. Once timing is present,
        // however, it is a complete current or original start fact and must
        // agree with the projection it can authoritatively represent.
        if ($observation->processIdentity !== null && $processIdentity !== null
            && !self::sameProcessIdentity($observation->processIdentity, $processIdentity)) {
            throw new InvalidArgumentException('Launch observation process identity conflicts with the current process identity.');
        }
        if ($observation->pid !== null && ($observation->processIdentity === null || $observation->pid !== $observation->processIdentity->pid)) {
            throw new InvalidArgumentException('Launch observation PID does not match its process identity.');
        }
        if ($observation->startObservation !== null) {
            if ($attemptStartObservation !== null
                && !self::sameStartObservation($observation->startObservation, $attemptStartObservation)) {
                throw new InvalidArgumentException('Launch observation start conflicts with the attempt start observation.');
            }
            if ($attemptStartObservation === null) {
                if ($startAnchorIdentity === null || $startObservation === null
                    || !self::sameProcessIdentity($observation->processIdentity, $startAnchorIdentity)
                    || !self::sameStartObservation($observation->startObservation, $startObservation)) {
                    throw new InvalidArgumentException('Launch observation original start conflicts with the durable start anchor.');
                }
            }
        }
    }

    private static function sameProcessIdentity(?ExecutionProcessIdentity $left, ?ExecutionProcessIdentity $right): bool
    {
        return $left === null ? $right === null : $right !== null
            && $left->pid === $right->pid && $left->processGroup === $right->processGroup
            && $left->session === $right->session && $left->startTicks === $right->startTicks
            && $left->bootId === $right->bootId;
    }

    private static function sameStartObservation(?ExecutionStartObservation $left, ?ExecutionStartObservation $right): bool
    {
        return $left === null ? $right === null : $right !== null
            && $left->wallMicroseconds === $right->wallMicroseconds
            && $left->monotonicNanoseconds === $right->monotonicNanoseconds;
    }

    private static function assertSourceFact(string $value, string $name): void
    {
        if ($value === '' || strlen($value) > 512 || str_contains($value, "\0")) {
            throw new InvalidArgumentException("Execution source {$name} is invalid.");
        }
    }

    private static function isPortableConfigurationFingerprint(string $value): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $value) === 1
            || preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
