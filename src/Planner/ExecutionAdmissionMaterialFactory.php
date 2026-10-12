<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Planner;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Yiisoft\Schedule\ExecutionMode;
use Yiisoft\Schedule\Config\{PreparedConfiguration, TimeoutPolicy};
use Yiisoft\Schedule\Integration\Async\{ExecutionDispatchReservation,ExecutionFence,ExecutionLedgerInterface,ExecutionLedgerRecord,ExecutionLedgerState,ExecutionOccurrenceCandidate,ExecutionRecoveryFence,ExecutionWorkerCapability};

use function is_array;
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
        private readonly ExecutionLedgerInterface $ledger,
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
            $policy = $this->assertPolicy($candidate->mapping->payload);
            $this->assertCanonicalTimeout($timeout, $candidate->mapping->timeout, $policy, 'candidate');
            $effectiveTimeout = $timeout;
        }
        $now = max(0, $this->clock->now()->getTimestamp());
        if ($existing !== null) {
            $fence = $existing->fence;
            $correlation = $existing->correlation;
            $configurationFingerprint = $existing->configurationFingerprint;
            $version = hash('sha256', PreparedConfiguration::canonical($existing->taskSnapshot));
            $recovery = $existing->recoveryFence ?? new ExecutionRecoveryFence('recovery-' . $fence->generation);
        } else {
            $fence = new ExecutionFence(1, ($this->newFence)(1));
            $correlation = $candidate->mapping->correlation;
            $configurationFingerprint = $candidate->mapping->configurationFingerprint;
            $version = hash('sha256', PreparedConfiguration::canonical($candidate->mapping->payload));
            $recovery = new ExecutionRecoveryFence('recovery-' . $fence->generation . '-' . substr(hash('sha256', $fence->token), 0, 16));
        }
        $metadata = $existing?->metadata ?? [
            'occurrenceCursor' => $candidate->cursor,
            'taskMappingIdentity' => $candidate->taskMappingIdentity(),
            'taskMappingContext' => $candidate->mapping->context,
            'sourceId' => $candidate->mapping->locator,
            'sourceRevision' => $candidate->mapping->context['sourceRevision'] ?? null,
        ];
        $sourceRevision = $metadata['sourceRevision'] ?? null;
        if (!is_string($sourceRevision) || $sourceRevision === '' || strlen($sourceRevision) > 512 || str_contains($sourceRevision, "\0")) {
            throw new InvalidArgumentException('Admission source revision is missing or invalid.');
        }
        $record = $existing ?? new ExecutionLedgerRecord(
            $candidate->occurrence,
            $correlation,
            1,
            $fence,
            ExecutionLedgerState::LAUNCH_REQUIRED,
            'launch-required',
            $configurationFingerprint,
            $candidate->mapping->payload,
            $now,
            $now,
            metadata: $metadata,
            recoveryFence: $recovery,
        );
        // An absent effective timeout means that dispatch has no execution
        // deadline. Keep that distinct from a synthesized timeout policy while
        // still satisfying the reservation's ordered, non-negative invariant.
        $deadline = $effectiveTimeout === null
            ? PHP_INT_MAX
            : $now + (int) ceil($effectiveTimeout->hardSeconds ?? $effectiveTimeout->softSeconds ?? PHP_INT_MAX);
        $reservation = $existing?->dispatchReservation ?? new ExecutionDispatchReservation($this->dispatcherId, $now, $deadline);
        $capability = ($policy['task']['mode'] ?? null) === ExecutionMode::ASYNC->value
            ? new ExecutionWorkerCapability($candidate->occurrence, $correlation->attemptId, $correlation->runId, $correlation->executionId, $fence->generation, $fence->token, $configurationFingerprint, $version, $this->ledgerLocator, $this->runtimeLocator, $sourceRevision, $policy)
            : null;
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
        return $timeout === null ? null : [$timeout->softSeconds, $timeout->hardSeconds];
    }

    /** @param array<string, mixed> $policy
     *  @return array{0: float|null, 1: float|null}|null
     */
    private function policyTimeout(array $policy): ?array
    {
        if (array_keys($policy) !== ['format', 'source', 'task'] || $policy['format'] !== PreparedConfiguration::TASK_FORMAT
            || !is_array($policy['task']) || array_keys($policy['task']) !== ['id', 'mode', 'timeout', 'metadata', 'schedule', 'trigger', 'overlap', 'missed']) {
            throw new InvalidArgumentException('Task policy shape is invalid.');
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
