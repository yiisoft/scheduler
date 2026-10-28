<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use Yiisoft\Scheduler\Config\PreparedConfiguration;
use Yiisoft\Scheduler\Integration\Async\ExecutionLedgerRecord;
use Yiisoft\Scheduler\Integration\Async\Recovery\RecoveryAdmissionValidatorInterface;
use Throwable;

/** Checks recovery against the currently pinned admission facts only. */
final class CurrentRecoveryAdmissionValidator implements RecoveryAdmissionValidatorInterface
{
    public function __construct(private readonly PreparedConfiguration $configuration, private readonly string $sourceRevision) {}

    public function accepts(ExecutionLedgerRecord $record, string $sourceIdentity): bool
    {
        if ($record->sourceIdentity !== $sourceIdentity || $record->sourceRevision !== $this->sourceRevision
            || $this->configuration->sourceId !== $sourceIdentity || $this->configuration->sourceRevision !== $this->sourceRevision) {
            return false;
        }
        try {
            $task = $this->configuration->task($record->occurrence->taskKey);
            $policy = $this->configuration->taskPolicyProjection($task);
            PreparedConfiguration::assertTaskPolicyProjection($record->taskSnapshot);
            if (($record->taskSnapshot['source'][0] ?? null) !== $record->sourceIdentity
                || ($record->taskSnapshot['source'][1] ?? null) !== $record->sourceRevision
                || ($policy['source'][0] ?? null) !== $record->sourceIdentity
                || ($policy['source'][1] ?? null) !== $record->sourceRevision) {
                return false;
            }
            return hash_equals($record->configurationFingerprint, $this->configuration->fingerprint())
                && hash_equals(PreparedConfiguration::canonical($record->taskSnapshot), PreparedConfiguration::canonical($policy));
        } catch (Throwable) {
            return false;
        }
    }
}
