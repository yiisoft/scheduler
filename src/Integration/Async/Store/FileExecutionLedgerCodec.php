<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Store;

use InvalidArgumentException;
use Throwable;
use Yiisoft\Scheduler\Config\PreparedConfiguration;
use Yiisoft\Scheduler\Integration\Async\{ExecutionIdentity, ExecutionDispatchReservation, ExecutionFence, ExecutionLaunchObservation, ExecutionLedgerRecord, ExecutionRecoveryFence, ExecutionOutcome, ExecutionOccurrenceKey, ExecutionProcessClosure};
use Yiisoft\Scheduler\ScalarMetadata;

use function count;
use function is_array;
use function is_int;
use function is_string;
use function strlen;

use const JSON_BIGINT_AS_STRING;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/** Strict, scalar JSON representation of an admitted ledger snapshot. */
final class FileExecutionLedgerCodec
{
    public const MAX_BYTES = 16 * 1024 * 1024;

    // ScalarMetadata permits depth 32. Snapshot objects add fixed transport
    // wrappers, so JSON decoding must not reuse the scalar-tree limit.
    private const JSON_DEPTH = 64;

    public function encode(ExecutionLedgerRecord $r): string
    {
        $value = [
            'version' => 5,
            'occurrence' => ['scheduleKey' => $r->occurrence->scheduleKey, 'taskKey' => $r->occurrence->taskKey, 'occurrenceId' => $r->occurrence->occurrenceId],
            'correlation' => ['scheduleKey' => $r->correlation->scheduleKey, 'taskKey' => $r->correlation->taskKey, 'occurrenceId' => $r->correlation->occurrenceId, 'attemptId' => $r->correlation->attemptId, 'runId' => $r->correlation->runId, 'executionId' => $r->correlation->executionId],
            'attempt' => $r->attempt,
            'fence' => ['generation' => $r->fence->generation, 'token' => $r->fence->token],
            'state' => $r->state,
            'launchIntent' => $r->launchIntent,
            'configurationFingerprint' => $r->configurationFingerprint,
            'taskSnapshot' => $r->taskSnapshot,
            'createdAt' => $r->createdAt,
            'updatedAt' => $r->updatedAt,
            'outcome' => $this->outcome($r->outcome),
            'metadata' => $r->metadata,
            'dispatchReservation' => $r->dispatchReservation === null ? null : ['dispatcherId' => $r->dispatchReservation->dispatcherId, 'reservedAt' => $r->dispatchReservation->reservedAt, 'deadline' => $r->dispatchReservation->deadline],
            'launchObservation' => $r->launchObservation === null ? null : ['pid' => $r->launchObservation->pid, 'error' => $r->launchObservation->error, 'classification' => $r->launchObservation->classification, 'observedAt' => $r->launchObservation->observedAt, 'closure' => $r->launchObservation->closure === null ? null : ['status' => $r->launchObservation->closure->status, 'exitCode' => $r->launchObservation->closure->exitCode, 'reason' => $r->launchObservation->closure->reason]],
            'recoveryFence' => $r->recoveryFence?->token,
            'revokedAt' => $r->revokedAt,
            'claimLeaseDeadline' => $r->claimLeaseDeadline,
        ];
        PreparedConfiguration::assertTaskPolicyProjection($r->taskSnapshot);
        ScalarMetadata::assertAggregateSupported($r->taskSnapshot, $r->metadata);
        try {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Ledger snapshot cannot be encoded.', 0, $e);
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Ledger snapshot is oversized.');
        }
        return $json;
    }

    public function decode(string $json): ExecutionLedgerRecord
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Ledger snapshot is malformed.');
        }
        try {
            $value = json_decode($json, true, self::JSON_DEPTH, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
            $a = $this->object($value, ['version', 'occurrence', 'correlation', 'attempt', 'fence', 'state', 'launchIntent', 'configurationFingerprint', 'taskSnapshot', 'createdAt', 'updatedAt', 'outcome', 'metadata', 'dispatchReservation', 'launchObservation', 'recoveryFence', 'revokedAt', 'claimLeaseDeadline']);
            if (!is_int($a['version']) || $a['version'] !== 5) {
                throw new InvalidArgumentException('Ledger snapshot version is unsupported.');
            }

            $occurrence = $this->object($a['occurrence'], ['scheduleKey', 'taskKey', 'occurrenceId']);
            $correlation = $this->object($a['correlation'], ['scheduleKey', 'taskKey', 'occurrenceId', 'attemptId', 'runId', 'executionId']);
            $fence = $this->object($a['fence'], ['generation', 'token']);
            /** @var list{string, string, string} $occurrenceValues */
            $occurrenceValues = $this->strings($occurrence, ['scheduleKey', 'taskKey', 'occurrenceId']);
            [$occurrenceSchedule, $occurrenceTask, $occurrenceId] = $occurrenceValues;
            /** @var list{string, string, string, string, string, string} $correlationValues */
            $correlationValues = $this->strings($correlation, ['scheduleKey', 'taskKey', 'occurrenceId', 'attemptId', 'runId', 'executionId']);
            [$correlationSchedule, $correlationTask, $correlationId, $attemptId, $runId, $executionId] = $correlationValues;
            $o = new ExecutionOccurrenceKey($occurrenceSchedule, $occurrenceTask, $occurrenceId);
            $c = new ExecutionIdentity($correlationSchedule, $correlationTask, $correlationId, $attemptId, $runId, $executionId);
            if (!$o->equals(new ExecutionOccurrenceKey($c->scheduleKey, $c->taskKey, $c->occurrenceId))) {
                throw new InvalidArgumentException('Ledger identity mismatch.');
            }
            if (!is_int($a['attempt']) || !is_int($a['createdAt']) || !is_int($a['updatedAt']) || !is_string($a['state']) || !is_string($a['launchIntent']) || !is_string($a['configurationFingerprint']) || !is_array($a['taskSnapshot']) || !is_array($a['metadata'])) {
                throw new InvalidArgumentException('Ledger scalar is invalid.');
            }
            PreparedConfiguration::assertTaskPolicyProjection($a['taskSnapshot']);
            ScalarMetadata::assertAggregateSupported($a['taskSnapshot'], $a['metadata']);
            if (!is_int($fence['generation']) || !is_string($fence['token'])) {
                throw new InvalidArgumentException('Ledger fence is invalid.');
            }

            $outcome = $this->decodeOutcome($a['outcome']);
            $reservation = $a['dispatchReservation'] === null ? null : $this->decodeReservation($a['dispatchReservation']);
            $observation = $a['launchObservation'] === null ? null : $this->decodeObservation($a['launchObservation']);
            if ($a['recoveryFence'] !== null && !is_string($a['recoveryFence'])) {
                throw new InvalidArgumentException('Recovery fence is invalid.');
            }
            $revokedAt = $a['revokedAt'] ?? null;
            $claimLeaseDeadline = $a['claimLeaseDeadline'] ?? null;
            foreach ([$revokedAt, $claimLeaseDeadline] as $timestamp) {
                if ($timestamp !== null && !is_int($timestamp)) {
                    throw new InvalidArgumentException('Ledger timestamp is invalid.');
                }
            }
            $revokedAt = $revokedAt === null ? null : (int) $revokedAt;
            $claimLeaseDeadline = $claimLeaseDeadline === null ? null : (int) $claimLeaseDeadline;
            return new ExecutionLedgerRecord($o, $c, $a['attempt'], new ExecutionFence($fence['generation'], $fence['token']), $a['state'], $a['launchIntent'], $a['configurationFingerprint'], $a['taskSnapshot'], $a['createdAt'], $a['updatedAt'], $outcome, $a['metadata'], $reservation, $observation, $a['recoveryFence'] === null ? null : new ExecutionRecoveryFence($a['recoveryFence']), $revokedAt, $claimLeaseDeadline);
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Ledger snapshot is malformed.', 0, $e);
        }
    }

    private function outcome(?ExecutionOutcome $o): ?array
    {
        return $o === null ? null : ['status' => $o->status, 'reason' => $o->reason, 'metadata' => $o->metadata];
    }

    private function decodeOutcome(mixed $value): ?ExecutionOutcome
    {
        if ($value === null) {
            return null;
        }
        $x = $this->object($value, ['status', 'reason', 'metadata']);
        if (!is_string($x['status']) || ($x['reason'] !== null && !is_string($x['reason'])) || !is_array($x['metadata'])) {
            throw new InvalidArgumentException('Outcome is invalid.');
        }
        return new ExecutionOutcome($x['status'], $x['reason'], $x['metadata']);
    }

    private function decodeReservation(mixed $value): ExecutionDispatchReservation
    {
        $x = $this->object($value, ['dispatcherId', 'reservedAt', 'deadline']);
        if (!is_string($x['dispatcherId']) || !is_int($x['reservedAt']) || !is_int($x['deadline'])) {
            throw new InvalidArgumentException('Dispatch reservation is invalid.');
        }
        return new ExecutionDispatchReservation($x['dispatcherId'], $x['reservedAt'], $x['deadline']);
    }

    private function decodeObservation(mixed $value): ExecutionLaunchObservation
    {
        $x = $this->object($value, ['pid', 'error', 'classification', 'observedAt', 'closure']);
        if (($x['pid'] !== null && !is_int($x['pid'])) || ($x['error'] !== null && !is_string($x['error'])) || !is_string($x['classification']) || !is_int($x['observedAt'])) {
            throw new InvalidArgumentException('Launch observation is invalid.');
        }
        return new ExecutionLaunchObservation($x['pid'], $x['error'], $x['classification'], $x['observedAt'], $this->decodeClosure($x['closure']));
    }

    private function decodeClosure(mixed $value): ?ExecutionProcessClosure
    {
        if ($value === null) {
            return null;
        }
        $x = $this->object($value, ['status', 'exitCode', 'reason']);
        if (!is_string($x['status']) || ($x['exitCode'] !== null && !is_int($x['exitCode'])) || !is_string($x['reason'])) {
            throw new InvalidArgumentException('Process closure is invalid.');
        }
        return match ($x['status']) {
            'unknown' => $x['exitCode'] === null ? ExecutionProcessClosure::unknown($x['reason']) : throw new InvalidArgumentException('Unknown process closure has an exit code.'),
            'confirmed' => ExecutionProcessClosure::confirmed($x['exitCode'], $x['reason']),
            default => throw new InvalidArgumentException('Process closure is invalid.'),
        };
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, array $keys): array
    {
        if (!is_array($value) || array_diff(array_keys($value), $keys) !== [] || array_diff($keys, array_keys($value)) !== [] || count($value) !== count($keys)) {
            throw new InvalidArgumentException('Ledger object shape is invalid.');
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Ledger object shape is invalid.');
            }
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $keys
     * @return list<string>
     */
    private function strings(array $value, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $item = $value[$key] ?? null;
            if (!is_string($item)) {
                throw new InvalidArgumentException('Ledger identity is invalid.');
            }
            $result[] = $item;
        }
        return $result;
    }
}
