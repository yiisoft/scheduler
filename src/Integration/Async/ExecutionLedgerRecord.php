<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;
use Yiisoft\Scheduler\Config\PreparedConfiguration;
use Yiisoft\Scheduler\ScalarMetadata;

use function strlen;

/** Immutable snapshot of the single durable execution-ledger record. */
final class ExecutionLedgerRecord
{
    public function __construct(
        public readonly ExecutionOccurrenceKey $occurrence,
        public readonly ExecutionIdentity $correlation,
        public readonly int $attempt,
        public readonly ExecutionFence $fence,
        public readonly string $state,
        public readonly string $launchIntent,
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
    ) {
        ExecutionLedgerState::assertValid($state);
        if ($attempt < 1 || $createdAt < 0 || $updatedAt < $createdAt) {
            throw new InvalidArgumentException('Execution ledger facts are invalid.');
        }
        if ($occurrence->taskKey !== $correlation->taskKey || $occurrence->occurrenceId !== $correlation->occurrenceId) {
            throw new InvalidArgumentException('Execution ledger identity does not match its correlation.');
        }
        if ($launchIntent === '' || strlen($launchIntent) > 4096) {
            throw new InvalidArgumentException('Execution ledger launch intent is invalid.');
        }
        if ($configurationFingerprint === '' || strlen($configurationFingerprint) > 4096 || !self::isPortableConfigurationFingerprint($configurationFingerprint)) {
            throw new InvalidArgumentException('Execution ledger configuration fingerprint is invalid.');
        }
        if (($taskSnapshot['format'] ?? null) !== PreparedConfiguration::TASK_FORMAT) {
            throw new InvalidArgumentException('Execution ledger task policy format is unsupported.');
        }
        ScalarMetadata::assertAggregateSupported($taskSnapshot, $metadata);
        if (ExecutionLedgerState::isTerminal($state) !== ($outcome !== null)) {
            throw new InvalidArgumentException('Terminal state and outcome must agree.');
        }
        if ($outcome !== null && $outcome->status !== $state) {
            throw new InvalidArgumentException('Terminal state does not match outcome.');
        }
        if ($revokedAt !== null && $revokedAt < $createdAt) {
            throw new InvalidArgumentException('Revocation timestamp is invalid.');
        }
        if ($claimLeaseDeadline !== null && $claimLeaseDeadline < $createdAt) {
            throw new InvalidArgumentException('Claim lease deadline is invalid.');
        }
        if (($state === ExecutionLedgerState::REVOKED) !== ($revokedAt !== null)) {
            throw new InvalidArgumentException('Revocation state and timestamp must agree.');
        }
    }

    public function isTerminal(): bool
    {
        return ExecutionLedgerState::isTerminal($this->state);
    }

    private static function isPortableConfigurationFingerprint(string $value): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $value) === 1
            || preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
