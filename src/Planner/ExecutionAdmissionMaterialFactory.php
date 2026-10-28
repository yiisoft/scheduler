<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Yiisoft\Scheduler\ExecutionMode;
use Yiisoft\Scheduler\Config\{PreparedConfiguration, TimeoutPolicy};
use Yiisoft\Scheduler\Integration\Async\{ExecutionDispatchReservation, ExecutionFence, ExecutionLaunchDescriptor, ExecutionLedgerQueryInterface, ExecutionLedgerRecord, ExecutionLedgerState, ExecutionOccurrenceCandidate, ExecutionRecoveryFence, ExecutionWorkerCapability};

use function is_array;
use function is_finite;
use function is_float;
use function is_string;
use function strlen;
use function str_contains;

use const PHP_INT_MAX;

/**
 * Builds admission facts without replacing identities which are already durable.
 * The fence factory is injected so applications can use a secure, deterministic
 * test seam without making randomness part of the planner contract.
 */
final class ExecutionAdmissionMaterialFactory
{
    /** @param callable(int): string $newFence */
    public function __construct(
        private readonly ExecutionLedgerQueryInterface $ledger,
        private readonly ClockInterface $clock,
        private readonly string $dispatcherId,
        private readonly string $ledgerLocator,
        private readonly string $runtimeLocator,
        private readonly mixed $newFence,
    ) {}

    public function forCandidate(ExecutionOccurrenceCandidate $candidate, ?TimeoutPolicy $timeout): ExecutionAdmissionMaterial
    {
        $existing = $this->ledger->observe($candidate->occurrence);
        if ($existing !== null) {
            // A durable admission is authoritative.  Do not compare the
            // caller's current timeout with it: re-admission must use the
            // exact candidate and policy that were admitted previously.
            if (!$candidate->matchesRecord($existing)) {
                throw new InvalidArgumentException('Durable execution identity does not match its admitted candidate.');
            }
            $policy = $this->assertPolicy($existing->taskSnapshot);
            $effectiveTimeout = $this->policyTimeoutPolicy($policy);
            $this->assertCanonicalTimeout($effectiveTimeout, $candidate->mapping->timeout, $policy, 'durable');
        } else {
            // Direct admission may still receive an unenforced TimeoutPolicy;
            // canonical transport uses null rather than [null, null].
            /** @var array<string, mixed> $candidatePolicy */
            $candidatePolicy = $candidate->mapping->payload;
            $candidateTask = $candidatePolicy['task'] ?? null;
            if (is_array($candidateTask) && ($candidateTask['timeout'] ?? null) === [null, null]) {
                $candidateTask['timeout'] = null;
                $candidatePolicy['task'] = $candidateTask;
            }
            $policy = $this->assertPolicy($candidatePolicy);
            $this->assertCanonicalTimeout($timeout, $candidate->mapping->timeout, $policy, 'candidate');
            $effectiveTimeout = $timeout?->enforced() ? $timeout : null;
        }
        $now = max(0, $this->clock->now()->getTimestamp());
        if ($existing !== null) {
            $fence = $existing->fence;
            $correlation = $existing->correlation;
            $configurationFingerprint = $existing->configurationFingerprint;
            $version = hash('sha256', PreparedConfiguration::canonical($existing->taskSnapshot));
            $recovery = $existing->recoveryFence ?? new ExecutionRecoveryFence('recovery-' . $fence->generation, $fence->generation);
        } else {
            $fence = new ExecutionFence(1, ($this->newFence)(1));
            $correlation = $candidate->mapping->correlation;
            $configurationFingerprint = $candidate->mapping->configurationFingerprint;
            $version = hash('sha256', PreparedConfiguration::canonical($candidate->mapping->payload));
            $recovery = new ExecutionRecoveryFence('recovery-' . $fence->generation . '-' . substr(hash('sha256', $fence->token), 0, 16), $fence->generation);
        }
        // Narrow the canonical policy source tuple once. Metadata and the
        // current candidate are projections which may only corroborate it.
        $policySource = $policy['source'] ?? null;
        if (!is_array($policySource)
            || !isset($policySource[0], $policySource[1])
            || !is_string($policySource[0])
            || !is_string($policySource[1])) {
            throw new InvalidArgumentException('Admission source tuple is missing or invalid.');
        }
        /** @var array{0: string, 1: string} $policySource */
        $sourceIdentity = $existing?->sourceIdentity ?? $policySource[0];
        $sourceRevision = $existing?->sourceRevision ?? $policySource[1];
        /** @var array<string, mixed> $metadata */
        $metadata = $existing?->metadata ?? [
            'occurrenceCursor' => $candidate->cursor,
            'taskMappingIdentity' => $candidate->taskMappingIdentity(),
            'taskMappingContext' => $candidate->mapping->context,
            'sourceId' => $policySource[0],
            'sourceRevision' => $policySource[1],
        ];
        if ($sourceIdentity === '' || strlen($sourceIdentity) > 512 || str_contains($sourceIdentity, "\0")) {
            throw new InvalidArgumentException('Admission source identity is missing or invalid.');
        }
        if ($sourceRevision === '' || strlen($sourceRevision) > 512 || str_contains($sourceRevision, "\0")) {
            throw new InvalidArgumentException('Admission source revision is missing or invalid.');
        }
        if (($metadata['sourceId'] ?? $sourceIdentity) !== $sourceIdentity
            || ($metadata['sourceRevision'] ?? $sourceRevision) !== $sourceRevision
            || $sourceIdentity !== ($candidate->mapping->context['sourceId'] ?? $candidate->mapping->locator)
            || $sourceRevision !== ($candidate->mapping->context['sourceRevision'] ?? null)
            || $policySource[0] !== $sourceIdentity
            || $policySource[1] !== $sourceRevision) {
            throw new InvalidArgumentException('Admission source facts do not match the canonical admitted source.');
        }
        $task = $policy['task'] ?? null;
        if (!is_array($task)) {
            throw new InvalidArgumentException('Admission task policy is invalid.');
        }
        $recoveryAgeValue = $task['recoveryMaxAge'] ?? null;
        if ($recoveryAgeValue !== null && (!is_float($recoveryAgeValue) || !is_finite($recoveryAgeValue) || $recoveryAgeValue <= 0)) {
            throw new InvalidArgumentException('Admission recovery age is invalid.');
        }
        /** @var float|null $recoveryAge */
        $recoveryAge = $recoveryAgeValue;
        $record = $existing ?? new ExecutionLedgerRecord(
            $candidate->occurrence,
            $correlation,
            1,
            $fence,
            ExecutionLedgerState::LAUNCH_REQUIRED,
            $configurationFingerprint,
            $policy,
            $now,
            $now,
            metadata: $metadata,
            recoveryFence: $recovery,
            sourceIdentity: $sourceIdentity,
            sourceRevision: $sourceRevision,
        );
        // An absent effective timeout means that dispatch has no execution
        // deadline. Keep that distinct from a synthesized timeout policy while
        // still satisfying the reservation's ordered, non-negative invariant.
        $timeoutSeconds = $effectiveTimeout?->hardSeconds ?? $effectiveTimeout?->softSeconds;
        if ($timeoutSeconds === null) {
            $deadline = PHP_INT_MAX;
        } else {
            $timeoutCeiling = ceil($timeoutSeconds);
            $available = PHP_INT_MAX - $now;
            // Check before converting the float ceiling to an integer or
            // adding it, so large finite values cannot overflow or become a
            // floating-point deadline.
            $deadline = $timeoutCeiling >= $available
                ? PHP_INT_MAX
                : $now + (int) $timeoutCeiling;
        }
        $reservation = $existing?->dispatchReservation ?? new ExecutionDispatchReservation($this->dispatcherId, $now, $deadline);
        $capability = null;
        if (($task['mode'] ?? null) === ExecutionMode::ASYNC->value) {
            $rootDescriptor = $existing?->launchDescriptor ?? new ExecutionLaunchDescriptor(
                $correlation->executionId,
                $fence->generation,
                occurrence: $candidate->occurrence,
            );
            $capability = new ExecutionWorkerCapability(
                $candidate->occurrence,
                $correlation->attemptId,
                $correlation->runId,
                $correlation->executionId,
                $fence->generation,
                $fence->token,
                $configurationFingerprint,
                $version,
                $this->ledgerLocator,
                $this->runtimeLocator,
                $sourceRevision,
                $policy,
                $recovery,
                $rootDescriptor,
                $reservation->permissionId,
            );
        }
        return new ExecutionAdmissionMaterial($record, $capability, $reservation, $recovery);
    }

    /** @return array<string, mixed> */
    private function assertPolicy(mixed $policy): array
    {
        PreparedConfiguration::assertTaskPolicyProjection($policy);
        /** @var array<string, mixed> $policy */
        return $policy;
    }

    /** @param array<string, mixed> $policy */
    private function assertCanonicalTimeout(?TimeoutPolicy $expected, ?TimeoutPolicy $candidate, array $policy, string $source): void
    {
        $values = $this->timeoutValues($expected);
        if ($values !== $this->timeoutValues($candidate) || $values !== $this->policyTimeout($policy)) {
            throw new InvalidArgumentException("{$source} execution timeout does not match its canonical task policy.");
        }
    }

    /** @param array<string, mixed> $policy */
    private function policyTimeoutPolicy(array $policy): ?TimeoutPolicy
    {
        $timeout = $this->policyTimeout($policy);
        return $timeout === null ? null : new TimeoutPolicy($timeout[0], $timeout[1]);
    }

    /** @return array{0: float|null, 1: float|null}|null */
    private function timeoutValues(?TimeoutPolicy $timeout): ?array
    {
        return $timeout === null || !$timeout->enforced() ? null : [$timeout->softSeconds, $timeout->hardSeconds];
    }

    /** @param array<string, mixed> $policy
     *  @return array{0: float|null, 1: float|null}|null
     */
    private function policyTimeout(array $policy): ?array
    {
        if (array_keys($policy) !== ['schema', 'source', 'task'] || $policy['schema'] !== PreparedConfiguration::TASK_SCHEMA
            || !is_array($policy['task']) || array_keys($policy['task']) !== ['id', 'mode', 'timeout', 'recoveryMaxAge', 'metadata', 'trigger', 'overlap', 'missed']) {
            throw new InvalidArgumentException('Task policy shape is invalid.');
        }
        $recoveryMaxAge = $policy['task']['recoveryMaxAge'];
        if ($policy['task']['mode'] === ExecutionMode::ASYNC->value
            && (!is_float($recoveryMaxAge) || !is_finite($recoveryMaxAge) || $recoveryMaxAge <= 0)) {
            throw new InvalidArgumentException('Task policy recovery max age is invalid.');
        }
        if ($policy['task']['mode'] === ExecutionMode::SYNC->value && $recoveryMaxAge !== null) {
            throw new InvalidArgumentException('Task policy recovery max age is invalid.');
        }
        $timeout = $policy['task']['timeout'];
        if ($timeout === null) {
            return null;
        }
        if (!is_array($timeout) || array_keys($timeout) !== [0, 1]
            || (($timeout[0] !== null) && !is_float($timeout[0]))
            || (($timeout[1] !== null) && !is_float($timeout[1]))) {
            throw new InvalidArgumentException('Task policy timeout is invalid.');
        }
        /** @var array{0: float|null, 1: float|null} $timeout */
        return $timeout;
    }
}
