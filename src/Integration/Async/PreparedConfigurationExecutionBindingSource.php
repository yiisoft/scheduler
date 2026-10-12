<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use InvalidArgumentException;
use Yiisoft\Schedule\Config\{ConfigurationSourceInterface, PreparedConfiguration};

use function hash;

/** Creates deterministic occurrence bindings from one pinned prepared configuration and owner run. */
final class PreparedConfigurationExecutionBindingSource implements ExecutionBindingSourceInterface
{
    private readonly string $sourceId;
    private readonly string $revision;

    public function __construct(
        private readonly ConfigurationSourceInterface $source,
        private readonly PreparedConfiguration $configuration,
        private readonly string $runId,
        private readonly ?ExecutionIdentityFactoryInterface $correlationFactory = null,
    ) {
        if ($configuration->sourceId === null || $configuration->sourceRevision === null
            || $configuration->sourceId !== $source->sourceId()
            || $configuration->sourceRevision !== $source->revision()) {
            throw new InvalidArgumentException('Prepared configuration source identity or revision is not pinned.');
        }
        $this->sourceId = $configuration->sourceId;
        $this->revision = $configuration->sourceRevision;
        $this->portable($runId, 'owner run');
    }

    public function map(string $scheduleKey, string $taskKey, string $occurrenceId, int $occurrenceTime): ExecutionBinding
    {
        new ExecutionOccurrenceKey($scheduleKey, $taskKey, $occurrenceId);
        if ($occurrenceTime < 0 || $occurrenceTime > 2147483647) {
            throw new InvalidArgumentException('Occurrence time is out of bounds.');
        }
        if ($this->source->sourceId() !== $this->sourceId || $this->source->revision() !== $this->revision) {
            throw new InvalidArgumentException('Prepared configuration source identity or revision changed.');
        }
        $task = $this->configuration->task($taskKey);
        if ((string) $task->scheduleId !== $scheduleKey) {
            throw new InvalidArgumentException('Unknown schedule for task.');
        }
        $timeout = $this->configuration->timeoutFor($task);
        $policy = $this->configuration->taskPolicyProjection($task);
        $version = $this->configuration->taskFingerprint($task);
        $correlation = $this->correlationFactory === null
            ? new ExecutionIdentity(
                $scheduleKey,
                $taskKey,
                $occurrenceId,
                'attempt-1',
                $this->runId,
                'e' . hash('sha256', $this->sourceId . "\0" . $this->revision . "\0" . $scheduleKey . "\0" . $taskKey . "\0" . $occurrenceId),
            )
            : $this->correlationFactory->create($this->sourceId, $this->revision, $taskKey, $occurrenceId);

        return new ExecutionBinding(
            $scheduleKey,
            $taskKey,
            $occurrenceId,
            $occurrenceTime,
            $correlation,
            $this->configuration->fingerprint(),
            $policy,
            ['sourceId' => $this->sourceId, 'sourceRevision' => $this->revision],
            $this->sourceId,
            $this->runId,
            mode: $task->mode->value,
            timeout: $timeout,
        );
    }

    private function portable(string $value, string $label): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
    }
}
