<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

/**
 * Reads future occurrences from application-owned configuration data.
 *
 * Implementations must not treat a read as acknowledgement.  The candidate
 * returned by next() remains current until acknowledge() or skip() succeeds,
 * allowing a failed mapping, rejected admission, or capacity/overlap decision
 * to retry without moving the cursor.
 */
interface ExecutionOccurrenceSourceInterface
{
    public function next(): ?ExecutionOccurrenceCandidate;

    /**
     * Acknowledge exactly one candidate after a durable admission commits.
     *
     * ExecutionAdmission represents either a newly committed record or an
     * idempotent observation of the same committed record.  Implementations
     * must compare the supplied candidate with the source's current candidate
     * across its complete immutable binding (cursor, occurrence key, full
     * correlation, configuration fingerprint, task snapshot, and mapping identity), not just
     * occurrence identity.  They must reject an admission unless the committed
     * record matches the candidate's cursor, full correlation, configuration fingerprint, task snapshot, and task
     * mapping identity.  The record metadata must carry the source binding as
     * `occurrenceCursor` and `taskMappingIdentity`.  Duplicate
     * acknowledgements must be rejected, and the cursor must advance exactly
     * once.  A caller must not manufacture an acknowledgement for an
     * uncommitted/rejected result.
     * InvalidArgumentException is the expected failure for those cases.
     */
    public function acknowledge(ExecutionOccurrenceCandidate $candidate, ExecutionAdmission $admission): void;

    /**
     * Skip exactly the current candidate for a bounded operational reason.
     * The checkpoint advances durably only after the candidate binding is
     * verified; stale or duplicate candidates must be rejected.
     */
    public function skip(ExecutionOccurrenceCandidate $candidate, OccurrenceSkipReason $reason): void;
}
