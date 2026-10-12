<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;
use JsonException;
use Yiisoft\Scheduler\Config\PreparedConfiguration;

use function is_array;
use function is_int;
use function is_string;
use function is_float;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_PRESERVE_ZERO_FRACTION;

/**
 * The complete, deliberately small scalar contract passed to a fresh worker.
 * It is not a process launch policy and contains no callable or backend object.
 */
final class ExecutionWorkerCapability
{
    public const VERSION = 3;
    private const MAX_TEXT = 512;
    private const MAX_CONFIGURATION_FINGERPRINT = 4096;

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
    ) {
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
        if (($policy['task']['mode'] ?? null) !== 'async') {
            throw new InvalidArgumentException('Worker capability requires an asynchronous task policy.');
        }
        new ExecutionFence($generation, $fenceToken);
        new ExecutionIdentity($occurrence->scheduleKey, $occurrence->taskKey, $occurrence->occurrenceId, $attemptId, $runId, $executionId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [
            'version' => self::VERSION,
            'occurrence' => [
                'schedule' => $this->occurrence->scheduleKey,
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
        ];
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
            $value = json_decode($transport, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Worker capability transport is not valid JSON.', 0, $e);
        }
        $keys = ['version', 'occurrence', 'attempt', 'run', 'execution', 'generation', 'fence', 'configurationFingerprint', 'taskVersion', 'ledger', 'runtime', 'sourceRevision', 'policy'];
        if (!is_array($value) || array_keys($value) !== $keys || !is_array($value['occurrence'])) {
            throw new InvalidArgumentException('Worker capability shape is invalid.');
        }
        $occurrence = $value['occurrence'];
        if (array_keys($occurrence) !== ['schedule', 'task', 'id']) {
            throw new InvalidArgumentException('Worker capability occurrence is invalid.');
        }
        foreach ($value as $key => $item) {
            if ($key !== 'occurrence' && $key !== 'policy' && (!is_string($item) && !is_int($item) && !is_float($item))) {
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

            || $value['version'] !== self::VERSION) {
            throw new InvalidArgumentException('Worker capability contains an invalid scalar value.');
        }
        /** @var array<string, mixed> $policy */
        $policy = $value['policy'];
        $schedule = (string) $occurrence['schedule'];
        $task = (string) $occurrence['task'];
        $id = (string) $occurrence['id'];
        return new self(
            new ExecutionOccurrenceKey($schedule, $task, $id),
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

    private function bounded(string $value, string $name, int $max = self::MAX_TEXT): void
    {
        if ($value === '' || strlen($value) > $max || str_contains($value, "\0")) {
            throw new InvalidArgumentException("Worker capability {$name} is invalid.");
        }
    }
}
