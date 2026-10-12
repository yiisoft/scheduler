<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use InvalidArgumentException;
use Yiisoft\Schedule\Config\TimeoutPolicy;
use Yiisoft\Schedule\ScalarMetadata;

use function in_array;
use function strlen;

/**
 * Application-owned immutable task descriptor for one occurrence.
 *
 */
final class ExecutionBinding
{
    public readonly ExecutionIdentity $correlation;
    public readonly ?TimeoutPolicy $timeout;
    public readonly bool $supervisionRequested;
    public readonly string $mode;

    /**
     * @param array<array-key, mixed> $payload
     * @param array<array-key, mixed> $context
     */
    public function __construct(
        public readonly string $scheduleKey,
        public readonly string $taskKey,
        public readonly string $occurrenceId,
        public readonly int $occurrenceTime,
        ExecutionIdentity $correlation,
        public readonly string $configurationFingerprint,
        public readonly array $payload = [],
        public readonly array $context = [],
        public readonly ?string $locator = null,
        public readonly ?string $runId = null,
        ?TimeoutPolicy $timeout = null,
        bool $supervisionRequested = false,
        string $mode = 'async',
    ) {
        $this->correlation = $correlation;
        $this->timeout = $timeout;
        $this->supervisionRequested = $supervisionRequested;
        $this->mode = $mode;
        foreach ([$scheduleKey, $taskKey, $occurrenceId] as $value) {
            if (!self::isPortableIdentifier($value)) {
                throw new InvalidArgumentException('Task mapping identifiers must be bounded and use portable characters.');
            }
        }
        if (!self::isPortableConfigurationFingerprint($configurationFingerprint)) {
            throw new InvalidArgumentException('Task mapping configuration fingerprint must be bounded and use portable characters.');
        }
        if ($scheduleKey !== $correlation->scheduleKey || $taskKey !== $correlation->taskKey || $occurrenceId !== $correlation->occurrenceId) {
            throw new InvalidArgumentException('Task mapping identity does not match its correlation.');
        }
        if ($locator !== null && ($locator === '' || strlen($locator) > 2048)) {
            throw new InvalidArgumentException('Task mapping locator is invalid.');
        }
        if ($runId !== $correlation->runId) {
            throw new InvalidArgumentException('Task mapping run identity does not match its correlation.');
        }
        if ($mode === '' || !in_array($mode, ['sync', 'async'], true)) {
            throw new InvalidArgumentException('Task mapping execution mode is invalid.');
        }
        ScalarMetadata::assertAggregateSupported($payload, $context);
    }

    private static function isPortableIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $value) === 1;
    }

    private static function isPortableConfigurationFingerprint(string $value): bool
    {
        return self::isPortableIdentifier($value) || preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
