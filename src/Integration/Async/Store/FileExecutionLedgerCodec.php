<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Store;

use InvalidArgumentException;
use Throwable;
use Yiisoft\Scheduler\Config\PreparedConfiguration;
use Yiisoft\Scheduler\Integration\Async\{
    ExecutionDispatchReservation, ExecutionFence, ExecutionIdentity, ExecutionLaunchDescriptor,
    ExecutionLaunchObservation, ExecutionLedgerRecord, ExecutionOccurrenceKey, ExecutionCommandScope,
    ExecutionOutcome, ExecutionProcessClosure, ExecutionProcessIdentity, ExecutionRecoveryFence,
    ExecutionStartObservation, ExecutionWorkerLaunchHandle,
};
use Yiisoft\Scheduler\ScalarMetadata;
use Yiisoft\Scheduler\Integration\Async\ExecutionRetiredGeneration;

use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function in_array;
use function strlen;
use function array_key_exists;

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
        return $this->encodeSnapshot($r, true);
    }

    public function decode(string $json): ExecutionLedgerRecord
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Ledger snapshot is malformed.');
        }
        try {
            $this->assertNoDuplicateObjectMembers($json);
            $value = json_decode($json, true, self::JSON_DEPTH, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
            if (!is_array($value) || ($value['schema'] ?? null) !== 'dev') {
                throw new InvalidArgumentException('Ledger snapshot schema is unsupported.');
            }
            return $this->decodeValue($this->stringKeyed($value));
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Ledger snapshot is malformed.', 0, $e);
        }
    }

    private function encodeSnapshot(ExecutionLedgerRecord $r, bool $includeHistory): string
    {
        $r->assertValid();
        $value = $this->snapshotValue($r, $includeHistory);
        if ($r->sourceIdentity === null || $r->sourceRevision === null) {
            throw new InvalidArgumentException('Fresh ledger records require canonical source facts.');
        }
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

    /** @return array<string, mixed> */
    private function snapshotValue(ExecutionLedgerRecord $r, bool $includeHistory): array
    {
        $value = [
            'schema' => 'dev',
            'occurrence' => ['taskKey' => $r->occurrence->taskKey, 'occurrenceId' => $r->occurrence->occurrenceId],
            'correlation' => ['taskKey' => $r->correlation->taskKey, 'occurrenceId' => $r->correlation->occurrenceId, 'attemptId' => $r->correlation->attemptId, 'runId' => $r->correlation->runId, 'executionId' => $r->correlation->executionId],
            'attempt' => $r->attempt,
            'fence' => ['generation' => $r->fence->generation, 'token' => $r->fence->token],
            'state' => $r->state,
            'configurationFingerprint' => $r->configurationFingerprint,
            'taskSnapshot' => $r->taskSnapshot,
            'createdAt' => $r->createdAt,
            'updatedAt' => $r->updatedAt,
            'outcome' => $this->outcome($r->outcome),
            'metadata' => $r->metadata,
            'dispatchReservation' => $r->dispatchReservation === null ? null : ['dispatcherId' => $r->dispatchReservation->dispatcherId, 'reservedAt' => $r->dispatchReservation->reservedAt, 'deadline' => $r->dispatchReservation->deadline, 'permissionId' => $r->dispatchReservation->permissionId, 'launchPhase' => $r->dispatchReservation->launchPhase],
            'launchObservation' => $r->launchObservation === null ? null : ['pid' => $r->launchObservation->pid, 'error' => $r->launchObservation->error, 'classification' => $r->launchObservation->classification, 'observedAt' => $r->launchObservation->observedAt, 'closure' => $r->launchObservation->closure === null ? null : ['status' => $r->launchObservation->closure->status, 'exitCode' => $r->launchObservation->closure->exitCode, 'reason' => $r->launchObservation->closure->reason], 'launchHandle' => $r->launchObservation->launchHandle?->value, 'processIdentity' => $r->launchObservation->processIdentity === null ? null : ['pid' => $r->launchObservation->processIdentity->pid, 'processGroup' => $r->launchObservation->processIdentity->processGroup, 'session' => $r->launchObservation->processIdentity->session, 'startTicks' => $r->launchObservation->processIdentity->startTicks, 'bootId' => $r->launchObservation->processIdentity->bootId], 'startObservation' => $r->launchObservation->startObservation === null ? null : ['wallMicroseconds' => $r->launchObservation->startObservation->wallMicroseconds, 'monotonicNanoseconds' => $r->launchObservation->startObservation->monotonicNanoseconds]],
            'recoveryFence' => $r->recoveryFence === null ? null : ['generation' => $r->recoveryFence->generation, 'token' => $r->recoveryFence->token],
            'revokedAt' => $r->revokedAt,
            'claimLeaseDeadline' => $r->claimLeaseDeadline,
            'sourceIdentity' => $r->sourceIdentity,
            'sourceRevision' => $r->sourceRevision,
            'launchDescriptor' => $r->launchDescriptor === null ? null : $this->encodeDescriptor($r->launchDescriptor),
            'processIdentity' => $r->processIdentity === null ? null : ['pid' => $r->processIdentity->pid, 'processGroup' => $r->processIdentity->processGroup, 'session' => $r->processIdentity->session, 'startTicks' => $r->processIdentity->startTicks, 'bootId' => $r->processIdentity->bootId],
            'startAnchorIdentity' => $r->startAnchorIdentity === null ? null : ['pid' => $r->startAnchorIdentity->pid, 'processGroup' => $r->startAnchorIdentity->processGroup, 'session' => $r->startAnchorIdentity->session, 'startTicks' => $r->startAnchorIdentity->startTicks, 'bootId' => $r->startAnchorIdentity->bootId],
            'startObservation' => $r->startObservation === null ? null : ['wallMicroseconds' => $r->startObservation->wallMicroseconds, 'monotonicNanoseconds' => $r->startObservation->monotonicNanoseconds],
            'attemptStartObservation' => $r->attemptStartObservation === null ? null : ['wallMicroseconds' => $r->attemptStartObservation->wallMicroseconds, 'monotonicNanoseconds' => $r->attemptStartObservation->monotonicNanoseconds],
            'recoveryAge' => $r->recoveryAge,
            'recoveryDeadline' => $r->recoveryDeadline,
            'recoveryDisposition' => $r->recoveryDisposition,
            'commandScope' => $r->commandScope === null ? null : ['descriptor' => $this->encodeDescriptor($r->commandScope->descriptor), 'reservation' => ['dispatcherId' => $r->commandScope->reservation->dispatcherId, 'reservedAt' => $r->commandScope->reservation->reservedAt, 'deadline' => $r->commandScope->reservation->deadline, 'permissionId' => $r->commandScope->reservation->permissionId, 'launchPhase' => $r->commandScope->reservation->launchPhase], 'observation' => $r->commandScope->observation === null ? null : $this->encodeObservation($r->commandScope->observation)],
            'retiredGenerations' => $includeHistory ? array_map(fn(ExecutionRetiredGeneration $h): array => ['attempt' => $this->snapshotValue($h->attempt, false), 'reason' => $h->reason, 'retiredAt' => $h->retiredAt], $r->retiredGenerations) : [],
        ];
        return $value;
    }

    /** @param array<string, mixed> $value */
    private function decodeValue(array $value): ExecutionLedgerRecord
    {
        try {
            $keys = ['schema', 'occurrence', 'correlation', 'attempt', 'fence', 'state', 'configurationFingerprint', 'taskSnapshot', 'createdAt', 'updatedAt', 'outcome', 'metadata', 'dispatchReservation', 'launchObservation', 'recoveryFence', 'revokedAt', 'claimLeaseDeadline', 'sourceIdentity', 'sourceRevision', 'launchDescriptor', 'processIdentity', 'startAnchorIdentity', 'startObservation', 'attemptStartObservation', 'recoveryAge', 'recoveryDeadline', 'recoveryDisposition', 'commandScope', 'retiredGenerations'];
            $a = $this->object($value, $keys);

            $occurrence = $this->object($a['occurrence'], ['taskKey', 'occurrenceId']);
            $correlation = $this->object($a['correlation'], ['taskKey', 'occurrenceId', 'attemptId', 'runId', 'executionId']);
            $fence = $this->object($a['fence'], ['generation', 'token']);
            /** @var list{string, string} $occurrenceValues */
            $occurrenceValues = $this->strings($occurrence, ['taskKey', 'occurrenceId']);
            [$occurrenceTask, $occurrenceId] = $occurrenceValues;
            /** @var list{string, string, string, string, string} $correlationValues */
            $correlationValues = $this->strings($correlation, ['taskKey', 'occurrenceId', 'attemptId', 'runId', 'executionId']);
            [$correlationTask, $correlationId, $attemptId, $runId, $executionId] = $correlationValues;
            $o = new ExecutionOccurrenceKey($occurrenceTask, $occurrenceId);
            $c = new ExecutionIdentity($correlationTask, $correlationId, $attemptId, $runId, $executionId);
            if (!$o->equals(new ExecutionOccurrenceKey($c->taskKey, $c->occurrenceId))) {
                throw new InvalidArgumentException('Ledger identity mismatch.');
            }
            if (!is_int($a['attempt']) || !is_int($a['createdAt']) || !is_int($a['updatedAt']) || !is_string($a['state']) || !is_string($a['configurationFingerprint']) || !is_array($a['taskSnapshot']) || !is_array($a['metadata'])) {
                throw new InvalidArgumentException('Ledger scalar is invalid.');
            }
            /** @var array<string, mixed> $taskSnapshot */
            $taskSnapshot = $a['taskSnapshot'];
            /** @var array<string, mixed> $metadata */
            $metadata = $a['metadata'];
            PreparedConfiguration::assertTaskPolicyProjection($taskSnapshot);
            ScalarMetadata::assertAggregateSupported($taskSnapshot, $metadata);
            if (!is_int($fence['generation']) || !is_string($fence['token'])) {
                throw new InvalidArgumentException('Ledger fence is invalid.');
            }

            $outcome = $this->decodeOutcome($a['outcome']);
            $reservation = $a['dispatchReservation'] === null ? null : $this->decodeReservation($a['dispatchReservation']);
            $observation = $a['launchObservation'] === null ? null : $this->decodeObservation($a['launchObservation']);
            $commandScope = null;
            if ($a['commandScope'] !== null) {
                $cs = $this->object($a['commandScope'], ['descriptor', 'reservation', 'observation']);
                $cr = $this->decodeReservation($cs['reservation']);
                $commandScope = new ExecutionCommandScope($this->decodeDescriptor($cs['descriptor']), $cr, $cs['observation'] === null ? null : $this->decodeObservation($cs['observation']));
            }
            $recoveryFence = null;
            if ($a['recoveryFence'] !== null) {
                $rf = $this->object($a['recoveryFence'], ['generation', 'token']);
                if (!is_int($rf['generation']) || !is_string($rf['token']) || $rf['generation'] !== $fence['generation']) {
                    throw new InvalidArgumentException('Recovery fence generation is invalid.');
                }
                $recoveryFence = new ExecutionRecoveryFence($rf['token'], $rf['generation']);
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
            if ($a['sourceIdentity'] !== null && !is_string($a['sourceIdentity'])) {
                throw new InvalidArgumentException('Source identity is invalid.');
            }
            if ($a['sourceRevision'] !== null && !is_string($a['sourceRevision'])) {
                throw new InvalidArgumentException('Source revision is invalid.');
            }
            $sourceIdentity = $a['sourceIdentity'];
            $sourceRevision = $a['sourceRevision'];
            if ($sourceIdentity === null || $sourceRevision === null) {
                throw new InvalidArgumentException('Canonical source facts are required.');
            }
            {
                $snapshotSource = $taskSnapshot['source'] ?? null;
                if (!is_array($snapshotSource) || count($snapshotSource) !== 2
                    || !is_string($snapshotSource[0] ?? null) || !is_string($snapshotSource[1] ?? null)) {
                    throw new InvalidArgumentException('Canonical source facts do not match the task policy.');
                }
                /** @var array{0: string, 1: string} $validatedSnapshotSource */
                $validatedSnapshotSource = [$snapshotSource[0], $snapshotSource[1]];
                if ($sourceIdentity !== $validatedSnapshotSource[0] || $sourceRevision !== $validatedSnapshotSource[1]) {
                    throw new InvalidArgumentException('Canonical source facts do not match the task policy.');
                }
            }
            $descriptor = $a['launchDescriptor'] !== null ? $this->decodeDescriptor($a['launchDescriptor']) : null;
            $identity = $a['processIdentity'] !== null ? $this->decodeProcessIdentity($a['processIdentity']) : null;
            $startAnchorIdentity = $a['startAnchorIdentity'] !== null ? $this->decodeProcessIdentity($a['startAnchorIdentity']) : null;
            $start = $a['startObservation'] !== null ? $this->decodeStartObservation($a['startObservation']) : null;
            $attemptStart = $a['attemptStartObservation'] !== null ? $this->decodeStartObservation($a['attemptStartObservation']) : null;
            $age = null;
            $deadline = null;
            {
                if ($a['recoveryAge'] !== null && (!is_float($a['recoveryAge']) || !is_finite($a['recoveryAge']) || $a['recoveryAge'] <= 0)) {
                    throw new InvalidArgumentException('Recovery age is invalid.');
                }
                if ($a['recoveryDeadline'] !== null && (!is_int($a['recoveryDeadline']) || $a['recoveryDeadline'] < $a['createdAt'] || $a['recoveryAge'] === null)) {
                    throw new InvalidArgumentException('Recovery deadline requires recovery age.');
                }
                if ($a['recoveryDisposition'] !== null && !is_string($a['recoveryDisposition'])) {
                    throw new InvalidArgumentException('Recovery disposition is invalid.');
                }
                /** @var float|null $age */
                $age = $a['recoveryAge'];
                /** @var int|null $deadline */
                $deadline = $a['recoveryDeadline'];
                if ($start !== null && (($age === null) !== ($deadline === null))) {
                    throw new InvalidArgumentException('Started recovery facts are incomplete.');
                }
            }
            if (!is_array($a['retiredGenerations'])) {
                throw new InvalidArgumentException('Retired generation history is invalid.');
            }
            $retiredGenerations = [];
            foreach ($a['retiredGenerations'] as $history) {
                if (!is_array($history) || count($history) !== 3 || !array_key_exists('attempt', $history) || !array_key_exists('reason', $history) || !array_key_exists('retiredAt', $history)
                    || !is_string($history['reason']) || !is_int($history['retiredAt'])) {
                    throw new InvalidArgumentException('Retired generation history is invalid.');
                }
                $retiredGenerations[] = new ExecutionRetiredGeneration(
                    $this->decodeValue($this->historyAttempt($history['attempt'])),
                    $history['reason'],
                    $history['retiredAt'],
                );
            }
            return new ExecutionLedgerRecord(
                occurrence: $o,
                correlation: $c,
                attempt: $a['attempt'],
                fence: new ExecutionFence($fence['generation'], $fence['token']),
                state: $a['state'],
                configurationFingerprint: $a['configurationFingerprint'],
                taskSnapshot: $taskSnapshot,
                createdAt: $a['createdAt'],
                updatedAt: $a['updatedAt'],
                outcome: $outcome,
                metadata: $metadata,
                dispatchReservation: $reservation,
                launchObservation: $observation,
                recoveryFence: $recoveryFence,
                revokedAt: $revokedAt,
                claimLeaseDeadline: $claimLeaseDeadline,
                sourceIdentity: $sourceIdentity,
                sourceRevision: $sourceRevision,
                launchDescriptor: $descriptor,
                processIdentity: $identity,
                startAnchorIdentity: $startAnchorIdentity,
                startObservation: $start,
                attemptStartObservation: $attemptStart,
                recoveryAge: $age,
                recoveryDeadline: $deadline,
                recoveryDisposition: is_string($a['recoveryDisposition']) ? $a['recoveryDisposition'] : null,
                commandScope: $commandScope,
                retiredGenerations: $retiredGenerations,
            );
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Ledger snapshot is malformed.', 0, $e);
        }
    }

    /** @return array<string, mixed> */
    private function historyAttempt(mixed $attempt): array
    {
        if (!is_array($attempt)) {
            throw new InvalidArgumentException('Retired generation history is invalid.');
        }
        return $this->stringKeyed($attempt);
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value): array
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Ledger object shape is invalid.');
            }
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    private function assertNoDuplicateObjectMembers(string $json): void
    {
        $length = strlen($json);
        $offset = 0;
        $skipWhitespace = static function () use (&$offset, $length, $json): void {
            while ($offset < $length && in_array($json[$offset], [' ', "\t", "\r", "\n"], true)) {
                ++$offset;
            }
        };
        $parseString = static function () use (&$offset, $length, $json): string {
            $start = $offset++;
            while ($offset < $length) {
                $character = $json[$offset];
                if ($character === '\\') {
                    $offset += 2;
                    continue;
                }
                if ($character === '"') {
                    $raw = substr($json, $start, ++$offset - $start);
                    $value = json_decode($raw, true, 1, JSON_THROW_ON_ERROR);
                    if (!is_string($value)) {
                        throw new InvalidArgumentException('Ledger snapshot member name is invalid.');
                    }
                    return $value;
                }
                ++$offset;
            }
            throw new InvalidArgumentException('Ledger snapshot is malformed.');
        };
        $parseValue = null;
        $parseValue = static function () use (&$parseValue, &$offset, $length, $json, $skipWhitespace, $parseString): void {
            $skipWhitespace();
            if ($offset >= $length) {
                throw new InvalidArgumentException('Ledger snapshot is malformed.');
            }
            $character = $json[$offset];
            if ($character === '"') {
                $parseString();
                return;
            }
            if ($character === '{') {
                ++$offset;
                /** @var array<string, true> $keys */
                $keys = [];
                $skipWhitespace();
                if ($offset < $length) {
                    $character = $json[$offset];
                    if ($character === '}') {
                        ++$offset;
                        return;
                    }
                }
                while (true) {
                    $skipWhitespace();
                    if ($offset >= $length) {
                        throw new InvalidArgumentException('Ledger snapshot is malformed.');
                    }
                    $character = $json[$offset];
                    if ($character !== '"') {
                        throw new InvalidArgumentException('Ledger snapshot is malformed.');
                    }
                    $key = $parseString();
                    if (isset($keys[$key])) {
                        throw new InvalidArgumentException('Ledger snapshot contains duplicate object members.');
                    }
                    $keys[$key] = true;
                    $skipWhitespace();
                    if ($offset >= $length) {
                        throw new InvalidArgumentException('Ledger snapshot is malformed.');
                    }
                    $character = $json[$offset++];
                    if ($character !== ':') {
                        throw new InvalidArgumentException('Ledger snapshot is malformed.');
                    }
                    $parseValue();
                    $skipWhitespace();
                    if ($offset >= $length) {
                        throw new InvalidArgumentException('Ledger snapshot is malformed.');
                    }
                    $character = $json[$offset];
                    if ($character === '}') {
                        ++$offset;
                        return;
                    }
                    $character = $json[$offset++];
                    if ($character !== ',') {
                        throw new InvalidArgumentException('Ledger snapshot is malformed.');
                    }
                }
            }
            if ($character === '[') {
                ++$offset;
                $skipWhitespace();
                if ($offset < $length) {
                    $character = $json[$offset];
                    if ($character === ']') {
                        ++$offset;
                        return;
                    }
                }
                while (true) {
                    $parseValue();
                    $skipWhitespace();
                    if ($offset >= $length) {
                        throw new InvalidArgumentException('Ledger snapshot is malformed.');
                    }
                    $character = $json[$offset];
                    if ($character === ']') {
                        ++$offset;
                        return;
                    }
                    $character = $json[$offset++];
                    if ($character !== ',') {
                        throw new InvalidArgumentException('Ledger snapshot is malformed.');
                    }
                }
            }
            $start = $offset;
            while ($offset < $length && !in_array($json[$offset], [' ', "\t", "\r", "\n", ',', ']', '}'], true)) {
                ++$offset;
            }
            if ($offset === $start) {
                throw new InvalidArgumentException('Ledger snapshot is malformed.');
            }
        };
        $parseValue();
        $skipWhitespace();
        if ($offset !== $length) {
            throw new InvalidArgumentException('Ledger snapshot is malformed.');
        }
    }

    /** @return array<string,mixed> */
    private function encodeObservation(ExecutionLaunchObservation $observation): array
    {
        return ['pid' => $observation->pid, 'error' => $observation->error, 'classification' => $observation->classification, 'observedAt' => $observation->observedAt, 'closure' => $observation->closure === null ? null : ['status' => $observation->closure->status, 'exitCode' => $observation->closure->exitCode, 'reason' => $observation->closure->reason], 'launchHandle' => null, 'processIdentity' => $observation->processIdentity === null ? null : ['pid' => $observation->processIdentity->pid, 'processGroup' => $observation->processIdentity->processGroup, 'session' => $observation->processIdentity->session, 'startTicks' => $observation->processIdentity->startTicks, 'bootId' => $observation->processIdentity->bootId], 'startObservation' => $observation->startObservation === null ? null : ['wallMicroseconds' => $observation->startObservation->wallMicroseconds, 'monotonicNanoseconds' => $observation->startObservation->monotonicNanoseconds]];
    }

    private function encodeDescriptor(ExecutionLaunchDescriptor $descriptor): array
    {
        return [
            'executionId' => $descriptor->executionId,
            'generation' => $descriptor->generation,
            'nonce' => $descriptor->nonce,
            'scopeKind' => $descriptor->scopeKind,
            'role' => $descriptor->role,
            'rootDescriptor' => $descriptor->rootDescriptor === null ? null : $this->encodeDescriptor($descriptor->rootDescriptor),
            'occurrence' => $descriptor->occurrence === null ? null : ['taskKey' => $descriptor->occurrence->taskKey, 'occurrenceId' => $descriptor->occurrence->occurrenceId],
        ];
    }

    private function decodeDescriptor(mixed $value): ExecutionLaunchDescriptor
    {
        $x = $this->object($value, ['executionId', 'generation', 'nonce', 'scopeKind', 'role', 'rootDescriptor', 'occurrence']);
        if (!is_string($x['executionId']) || !is_int($x['generation']) || !is_string($x['nonce']) || !is_string($x['scopeKind']) || !is_string($x['role'])) {
            throw new InvalidArgumentException('Launch descriptor is invalid.');
        }
        $root = $x['rootDescriptor'] === null ? null : $this->decodeDescriptor($x['rootDescriptor']);
        $occurrence = null;
        if ($x['occurrence'] !== null) {
            $o = $this->object($x['occurrence'], ['taskKey', 'occurrenceId']);
            if (!is_string($o['taskKey']) || !is_string($o['occurrenceId'])) {
                throw new InvalidArgumentException('Launch descriptor occurrence is invalid.');
            }
            $occurrence = new ExecutionOccurrenceKey($o['taskKey'], $o['occurrenceId']);
        }
        return new ExecutionLaunchDescriptor($x['executionId'], $x['generation'], $x['nonce'], $x['scopeKind'], $x['role'], $root, $occurrence);
    }

    private function decodeProcessIdentity(mixed $value): ExecutionProcessIdentity
    {
        $x = $this->object($value, ['pid', 'processGroup', 'session', 'startTicks', 'bootId']);
        if (!is_int($x['pid']) || !is_int($x['processGroup']) || !is_int($x['session']) || !is_string($x['startTicks']) || !is_string($x['bootId'])) {
            throw new InvalidArgumentException('Process identity is invalid.');
        }
        return new ExecutionProcessIdentity($x['pid'], $x['processGroup'], $x['session'], $x['startTicks'], $x['bootId']);
    }

    private function decodeStartObservation(mixed $value): ExecutionStartObservation
    {
        $x = $this->object($value, ['wallMicroseconds', 'monotonicNanoseconds']);
        if (!is_int($x['wallMicroseconds']) || !is_int($x['monotonicNanoseconds'])) {
            throw new InvalidArgumentException('Start observation is invalid.');
        }
        return new ExecutionStartObservation($x['wallMicroseconds'], $x['monotonicNanoseconds']);
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
        $x = $this->object($value, ['dispatcherId', 'reservedAt', 'deadline', 'permissionId', 'launchPhase']);
        if (!is_string($x['dispatcherId']) || !is_int($x['reservedAt']) || !is_int($x['deadline']) || !is_string($x['permissionId']) || !is_string($x['launchPhase'])) {
            throw new InvalidArgumentException('Dispatch reservation is invalid.');
        }
        return new ExecutionDispatchReservation($x['dispatcherId'], $x['reservedAt'], $x['deadline'], $x['permissionId'], $x['launchPhase']);
    }

    private function decodeObservation(mixed $value): ExecutionLaunchObservation
    {
        $x = $this->object($value, ['pid', 'error', 'classification', 'observedAt', 'closure', 'launchHandle', 'processIdentity', 'startObservation']);
        if (($x['pid'] !== null && !is_int($x['pid'])) || ($x['error'] !== null && !is_string($x['error'])) || !is_string($x['classification']) || !is_int($x['observedAt']) || ($x['launchHandle'] !== null && !is_string($x['launchHandle']))) {
            throw new InvalidArgumentException('Launch observation is invalid.');
        }
        return new ExecutionLaunchObservation($x['pid'], $x['error'], $x['classification'], $x['observedAt'], $this->decodeClosure($x['closure']), $x['launchHandle'] === null ? null : new ExecutionWorkerLaunchHandle($x['launchHandle']), $x['processIdentity'] === null ? null : $this->decodeProcessIdentity($x['processIdentity']), $x['startObservation'] === null ? null : $this->decodeStartObservation($x['startObservation']));
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
