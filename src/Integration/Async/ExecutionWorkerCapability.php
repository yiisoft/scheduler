<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;
use JsonException;
use Yiisoft\Scheduler\Config\PreparedConfiguration;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function strlen;
use function preg_match;
use function str_contains;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_PRESERVE_ZERO_FRACTION;

/**
 * The complete, deliberately small scalar contract passed to a fresh worker.
 * It is not a process launch policy and contains no callable or backend object.
 */
final class ExecutionWorkerCapability
{
    public const SCHEMA = 'dev';
    private const MAX_TEXT = 512;
    private const MAX_CONFIGURATION_FINGERPRINT = 4096;
    public readonly ExecutionRecoveryFence $recoveryFence;

    public function __construct(
        public readonly ExecutionOccurrenceKey $occurrence,
        public readonly string $attemptId,
        public readonly string $runId,
        public readonly string $executionId,
        public readonly int $generation,
        public readonly string $fenceToken,
        public readonly string $configurationFingerprint,
        public readonly string $taskVersion,
        public readonly string $ledgerLocator,
        public readonly string $runtimeLocator,
        public readonly string $sourceRevision,
        /** @var array<string, mixed> command-free admitted task policy */
        public readonly array $policy = [],
        ?ExecutionRecoveryFence $recoveryFence = null,
        /** Exact root launch facts; process-local receipt contents are never transported. */
        public readonly ?ExecutionLaunchDescriptor $launchDescriptor = null,
        public readonly ?string $permissionId = null,
    ) {
        $recoveryFence ??= new ExecutionRecoveryFence('recovery-' . $generation . '-' . substr(hash('sha256', $fenceToken), 0, 16), $generation);
        $this->recoveryFence = $recoveryFence;
        if ($recoveryFence->generation !== $generation) {
            throw new InvalidArgumentException('Worker recovery fence generation is invalid.');
        }
        if ($generation < 1) {
            throw new InvalidArgumentException('Worker capability generation is invalid.');
        }
        $this->bounded($attemptId, 'attempt identity');
        $this->bounded($runId, 'run identity');
        $this->bounded($executionId, 'execution identity');
        $this->bounded($fenceToken, 'fence token');
        $this->bounded($configurationFingerprint, 'configuration fingerprint', self::MAX_CONFIGURATION_FINGERPRINT);
        if (!self::isPortableConfigurationFingerprint($configurationFingerprint)) {
            throw new InvalidArgumentException('Worker capability configuration fingerprint is invalid.');
        }
        $this->bounded($taskVersion, 'task version');
        $this->bounded($ledgerLocator, 'ledger locator');
        $this->bounded($runtimeLocator, 'runtime locator');
        $this->bounded($sourceRevision, 'source revision');
        PreparedConfiguration::assertTaskPolicyProjection($policy);
        $policySource = $policy['source'] ?? null;
        if (!is_array($policySource) || !isset($policySource[0], $policySource[1])
            || !is_string($policySource[0]) || !is_string($policySource[1])
            || $policySource[0] === '' || $policySource[1] !== $sourceRevision) {
            throw new InvalidArgumentException('Worker capability source facts do not match its task policy.');
        }
        if (($policy['task']['mode'] ?? null) !== 'async') {
            throw new InvalidArgumentException('Worker capability requires an asynchronous task policy.');
        }
        new ExecutionFence($generation, $fenceToken);
        new ExecutionIdentity($occurrence->taskKey, $occurrence->occurrenceId, $attemptId, $runId, $executionId);
        if (($launchDescriptor === null) !== ($permissionId === null)
            || ($permissionId !== null && preg_match('/^[a-f0-9]{32}$/D', $permissionId) !== 1)
            || ($launchDescriptor !== null && ($launchDescriptor->role !== ExecutionLaunchDescriptor::ROLE_WORKER
                || $launchDescriptor->scopeKind !== ExecutionLaunchDescriptor::SCOPE_KIND
                || $launchDescriptor->executionId !== $executionId
                || $launchDescriptor->generation !== $generation
                || $launchDescriptor->occurrence === null
                || !$launchDescriptor->occurrence->equals($occurrence)))) {
            throw new InvalidArgumentException('Worker launch handoff facts are invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [
            'schema' => self::SCHEMA,
            'occurrence' => [
                'task' => $this->occurrence->taskKey,
                'id' => $this->occurrence->occurrenceId,
            ],
            'attempt' => $this->attemptId,
            'run' => $this->runId,
            'execution' => $this->executionId,
            'generation' => $this->generation,
            'fence' => $this->fenceToken,
            'configurationFingerprint' => $this->configurationFingerprint,
            'taskVersion' => $this->taskVersion,
            'ledger' => $this->ledgerLocator,
            'runtime' => $this->runtimeLocator,
            'sourceRevision' => $this->sourceRevision,
            'policy' => $this->policy,
            'recoveryFence' => ['generation' => $this->recoveryFence->generation, 'token' => $this->recoveryFence->token],
        ];
        if ($this->launchDescriptor !== null) {
            $result['launchDescriptor'] = [
                'execution' => $this->launchDescriptor->executionId,
                'generation' => $this->launchDescriptor->generation,
                'nonce' => $this->launchDescriptor->nonce,
                'scope' => $this->launchDescriptor->scopeKind,
                'role' => $this->launchDescriptor->role,
                'occurrence' => ['task' => $this->launchDescriptor->occurrence?->taskKey, 'id' => $this->launchDescriptor->occurrence?->occurrenceId],
            ];
            $result['permissionId'] = $this->permissionId;
        }
        return $result;
    }

    public function encode(): string
    {
        try {
            return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Worker capability cannot be encoded.', 0, $e);
        }
    }

    /** @param mixed $transport */
    public static function decode(mixed $transport): self
    {
        if (!is_string($transport) || strlen($transport) > 8192 || str_contains($transport, "\0")) {
            throw new InvalidArgumentException('Worker capability transport is invalid.');
        }
        try {
            self::assertNoDuplicateObjectMembers($transport);
            $value = json_decode($transport, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Worker capability transport is not valid JSON.', 0, $e);
        }
        $keys = ['schema', 'occurrence', 'attempt', 'run', 'execution', 'generation', 'fence', 'configurationFingerprint', 'taskVersion', 'ledger', 'runtime', 'sourceRevision', 'policy', 'recoveryFence'];
        $handoffKeys = [...$keys, 'launchDescriptor', 'permissionId'];
        if (!is_array($value) || !in_array(array_keys($value), [$keys, $handoffKeys], true) || !is_array($value['occurrence']) || !is_array($value['recoveryFence'])) {
            throw new InvalidArgumentException('Worker capability shape is invalid.');
        }
        /** @var array<string, mixed> $occurrence */
        $occurrence = $value['occurrence'];
        if (array_keys($occurrence) !== ['task', 'id']) {
            throw new InvalidArgumentException('Worker capability occurrence is invalid.');
        }
        foreach ($value as $key => $item) {
            if ($key !== 'occurrence' && $key !== 'policy' && $key !== 'recoveryFence' && $key !== 'launchDescriptor' && (!is_string($item) && !is_int($item) && !is_float($item))) {
                throw new InvalidArgumentException('Worker capability contains a non-scalar value.');
            }
        }
        foreach ($occurrence as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException('Worker capability occurrence contains a non-scalar value.');
            }
        }
        if (!is_string($value['attempt']) || !is_string($value['run']) || !is_string($value['execution'])
            || !is_int($value['generation']) || !is_string($value['fence']) || !is_string($value['configurationFingerprint'])
            || !is_string($value['taskVersion']) || !is_string($value['ledger']) || !is_string($value['runtime'])
            || !is_string($value['sourceRevision']) || !is_array($value['policy'])

            || $value['schema'] !== self::SCHEMA) {
            throw new InvalidArgumentException('Worker capability contains an invalid scalar value.');
        }
        /** @var array{schema: mixed, source: array{0: mixed, 1: mixed}, task: array{mode: mixed}} $policy */
        $policy = $value['policy'];
        /** @var array<string, mixed> $recovery */
        $recovery = $value['recoveryFence'];
        if (array_keys($recovery) !== ['generation', 'token'] || !is_int($recovery['generation']) || !is_string($recovery['token'])) {
            throw new InvalidArgumentException('Worker capability recovery fence is invalid.');
        }
        $task = (string) $occurrence['task'];
        $id = (string) $occurrence['id'];
        $descriptor = null;
        $permission = null;
        if (array_key_exists('launchDescriptor', $value) || array_key_exists('permissionId', $value)) {
            if (!is_array($value['launchDescriptor'] ?? null) || !is_string($value['permissionId'] ?? null)) {
                throw new InvalidArgumentException('Worker launch handoff is invalid.');
            }
            /** @var array{execution: mixed, generation: mixed, nonce: mixed, scope: mixed, role: mixed, occurrence: mixed} $launch */
            $launch = $value['launchDescriptor'];
            if (array_keys($launch) !== ['execution', 'generation', 'nonce', 'scope', 'role', 'occurrence']
                || !is_int($launch['generation']) || !is_string($launch['execution']) || !is_string($launch['nonce'])
                || !is_string($launch['scope']) || !is_string($launch['role'])) {
                throw new InvalidArgumentException('Worker launch descriptor is invalid.');
            }
            $launchOccurrence = $launch['occurrence'];
            if (!is_array($launchOccurrence) || array_keys($launchOccurrence) !== ['task', 'id']
                || !is_string($launchOccurrence['task']) || !is_string($launchOccurrence['id'])) {
                throw new InvalidArgumentException('Worker launch descriptor is invalid.');
            }
            /** @var array{execution: string, generation: int, nonce: string, scope: string, role: string, occurrence: array{task: string, id: string}} $launch */
            /** @var array{task: string, id: string} $launchOccurrence */
            /** @var string $permission */
            $permission = $value['permissionId'];
            $descriptor = new ExecutionLaunchDescriptor($launch['execution'], $launch['generation'], $launch['nonce'], $launch['scope'], $launch['role'], occurrence: new ExecutionOccurrenceKey($launchOccurrence['task'], $launchOccurrence['id']));
        }
        return new self(
            new ExecutionOccurrenceKey($task, $id),
            $value['attempt'],
            $value['run'],
            $value['execution'],
            $value['generation'],
            $value['fence'],
            $value['configurationFingerprint'],
            $value['taskVersion'],
            $value['ledger'],
            $value['runtime'],
            $value['sourceRevision'],
            $policy,
            new ExecutionRecoveryFence($recovery['token'], $recovery['generation']),
            $descriptor,
            $permission,
        );
    }

    /** Safe for logs; the fencing secret is intentionally omitted. */
    public function diagnostic(): array
    {
        $result = $this->toArray();
        $result['fence'] = '[redacted]';
        return $result;
    }

    private static function isPortableConfigurationFingerprint(string $value): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $value) === 1
            || preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function assertNoDuplicateObjectMembers(string $json): void
    {
        $length = strlen($json);
        $offset = 0;
        $skip = static function () use (&$offset, $length, $json): void {
            while ($offset < $length && in_array($json[$offset], [' ', "\t", "\r", "\n"], true)) {
                ++$offset;
            }
        };
        $string = static function () use (&$offset, $length, $json): string {
            $start = $offset++;
            while ($offset < $length) {
                if ($json[$offset] === '\\') {
                    $offset += 2;
                    continue;
                }
                if ($json[$offset] === '"') {
                    $raw = substr($json, $start, ++$offset - $start);
                    $value = json_decode($raw, true, 1, JSON_THROW_ON_ERROR);
                    if (!is_string($value)) {
                        throw new InvalidArgumentException('Worker capability member name is invalid.');
                    }
                    return $value;
                }
                ++$offset;
            }
            throw new InvalidArgumentException('Worker capability transport is malformed.');
        };
        $parse = null;
        $parse = static function () use (&$parse, &$offset, $length, $json, $skip, $string): void {
            $skip();
            if ($offset >= $length) {
                throw new InvalidArgumentException('Worker capability transport is malformed.');
            }
            $character = $json[$offset];
            if ($character === '"') {
                $string();
                return;
            }
            if ($character === '{') {
                ++$offset;
                $keys = [];
                $skip();
                if ($offset < $length && $json[$offset] === '}') {
                    ++$offset;
                    return;
                }
                while (true) {
                    $skip();
                    if ($offset >= $length || $json[$offset] !== '"') {
                        throw new InvalidArgumentException('Worker capability transport is malformed.');
                    }
                    $key = $string();
                    if (isset($keys[$key])) {
                        throw new InvalidArgumentException('Worker capability contains duplicate object members.');
                    }
                    $keys[$key] = true;
                    $skip();
                    if ($offset >= $length || $json[$offset++] !== ':') {
                        throw new InvalidArgumentException('Worker capability transport is malformed.');
                    }
                    $parse();
                    $skip();
                    if ($offset >= $length) {
                        throw new InvalidArgumentException('Worker capability transport is malformed.');
                    }
                    if (substr($json, $offset, 1) === '}') {
                        ++$offset;
                        return;
                    }
                    if ($json[$offset++] !== ',') {
                        throw new InvalidArgumentException('Worker capability transport is malformed.');
                    }
                }
            }
            if ($character === '[') {
                ++$offset;
                $skip();
                if ($offset < $length && $json[$offset] === ']') {
                    ++$offset;
                    return;
                }
                while (true) {
                    $parse();
                    $skip();
                    if ($offset >= $length) {
                        throw new InvalidArgumentException('Worker capability transport is malformed.');
                    }
                    if ($json[$offset] === ']') {
                        ++$offset;
                        return;
                    }
                    if ($json[$offset++] !== ',') {
                        throw new InvalidArgumentException('Worker capability transport is malformed.');
                    }
                }
            }
            $start = $offset;
            while ($offset < $length && !in_array($json[$offset], [' ', "\t", "\r", "\n", ',', ']', '}'], true)) {
                ++$offset;
            }
            if ($offset === $start) {
                throw new InvalidArgumentException('Worker capability transport is malformed.');
            }
        };
        $parse();
        $skip();
        if ($offset !== $length) {
            throw new InvalidArgumentException('Worker capability transport is malformed.');
        }
    }

    private function bounded(string $value, string $name, int $max = self::MAX_TEXT): void
    {
        if ($value === '' || strlen($value) > $max || str_contains($value, "\0")) {
            throw new InvalidArgumentException("Worker capability {$name} is invalid.");
        }
    }
}
