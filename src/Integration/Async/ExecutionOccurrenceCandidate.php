<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function strlen;
use function is_array;

/**
 * One future occurrence derived from an application-owned configuration source.
 *
 * The mapping is an immutable snapshot: acknowledging this candidate does not
 * make a later configuration change part of the admitted execution record.
 */
final class ExecutionOccurrenceCandidate
{
    public function __construct(
        public readonly ExecutionOccurrenceKey $occurrence,
        public readonly ExecutionBinding $mapping,
        public readonly string $cursor,
    ) {
        if (
            $occurrence->scheduleKey !== $mapping->scheduleKey
            || $occurrence->taskKey !== $mapping->taskKey
            || $occurrence->occurrenceId !== $mapping->occurrenceId
        ) {
            throw new InvalidArgumentException('Occurrence candidate identity does not match its task mapping.');
        }
        if ($cursor === '' || strlen($cursor) > 2048 || preg_match('/^[A-Za-z0-9._~:-]+$/D', $cursor) !== 1) {
            throw new InvalidArgumentException('Occurrence candidate cursor is invalid.');
        }
    }

    public function isSameOccurrence(self $other): bool
    {
        return $this->occurrence->equals($other->occurrence);
    }

    /**
     * Compare the complete immutable candidate returned by the source.
     *
     * Occurrence identity alone is not sufficient: acknowledgement must be
     * for the exact source position, task snapshot, and mapping context that is still current.
     */
    public function isSameCandidate(self $other): bool
    {
        return $this->occurrence->equals($other->occurrence)
            && $this->cursor === $other->cursor
            && $this->mapping->correlation->scheduleKey === $other->mapping->correlation->scheduleKey
            && $this->mapping->correlation->taskKey === $other->mapping->correlation->taskKey
            && $this->mapping->correlation->occurrenceId === $other->mapping->correlation->occurrenceId
            && $this->mapping->correlation->attemptId === $other->mapping->correlation->attemptId
            && $this->mapping->correlation->runId === $other->mapping->correlation->runId
            && $this->mapping->correlation->executionId === $other->mapping->correlation->executionId
            && $this->mapping->configurationFingerprint === $other->mapping->configurationFingerprint
            && $this->mapping->payload === $other->mapping->payload
            && $this->mapping->context === $other->mapping->context
            && $this->taskMappingIdentity() === $other->taskMappingIdentity();
    }

    /**
     * Verify the complete immutable acknowledgement binding carried by a record.
     *
     * Occurrence identity alone is deliberately insufficient: the source cursor,
     * correlation, configurationFingerprint, snapshot, context, and mapping identity must all
     * be the values that were durably admitted.
     */
    public function matchesRecord(ExecutionLedgerRecord $record): bool
    {
        $metadata = $record->metadata;
        $admittedIdentity = $metadata['taskMappingIdentity'] ?? null;
        $admittedContext = $metadata['taskMappingContext']
            ?? (is_array($admittedIdentity) ? ($admittedIdentity['context'] ?? null) : null);

        return $this->occurrence->equals($record->occurrence)
            && self::sameCorrelation($this->mapping->correlation, $record->correlation)
            && $this->mapping->configurationFingerprint === $record->configurationFingerprint
            && $this->mapping->payload === $record->taskSnapshot
            && $this->mapping->context === $admittedContext
            && ($metadata['occurrenceCursor'] ?? null) === $this->cursor
            && $admittedIdentity === $this->taskMappingIdentity();
    }

    public function matchesAdmission(ExecutionAdmission $admission): bool
    {
        return $this->matchesRecord($admission->record);
    }

    /** @return array<string, mixed> */
    public function taskMappingIdentity(): array
    {
        return [
            'schedule' => $this->mapping->scheduleKey,
            'task' => $this->mapping->taskKey,
            'occurrence' => $this->mapping->occurrenceId,
            'occurrenceTime' => $this->mapping->occurrenceTime,
            'locator' => $this->mapping->locator,
            'runId' => $this->mapping->runId,
            'mode' => $this->mapping->mode,
            'timeout' => $this->mapping->timeout === null ? null : [
                'soft' => $this->mapping->timeout->softSeconds,
                'hard' => $this->mapping->timeout->hardSeconds,
            ],
            'supervisionRequested' => $this->mapping->supervisionRequested,
            // Include context in the durable identity so older admission paths
            // still carry the complete binding without a separate field.
            'context' => $this->mapping->context,
        ];
    }

    private static function sameCorrelation(ExecutionIdentity $left, ExecutionIdentity $right): bool
    {
        return $left->scheduleKey === $right->scheduleKey
            && $left->taskKey === $right->taskKey
            && $left->occurrenceId === $right->occurrenceId
            && $left->attemptId === $right->attemptId
            && $left->runId === $right->runId
            && $left->executionId === $right->executionId;
    }
}
