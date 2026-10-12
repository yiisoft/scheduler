<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Config;

use InvalidArgumentException;
use LogicException;
use Yiisoft\Scheduler\Callable\ContainerProvider;
use Yiisoft\Scheduler\Executor\CommandExecutorMap;
use Yiisoft\Scheduler\ExecutionMode;
use Yiisoft\Scheduler\ScalarMetadata;
use Yiisoft\Scheduler\Task\TaskId;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;

/** Process-local prepared scheduling policy. Command handles never enter its projections. */
final class PreparedConfiguration
{
    public const TASK_FORMAT = 4;

    /** @var array<string, TaskDefinition> */
    public readonly array $tasks;
    public readonly TimeoutPolicy $timeout;
    public readonly ?string $sourceId;
    public readonly ?string $sourceRevision;
    public readonly int $asyncWorkerCapacity;

    /** @param list<TaskDefinition> $tasks */
    public function __construct(
        array $tasks = [],
        ?TimeoutPolicy $timeout = null,
        ?string $sourceId = null,
        ?string $sourceRevision = null,
        int $asyncWorkerCapacity = 16,
        public readonly ?CommandExecutorMap $commandExecutorMap = null,
        public readonly ContainerProvider $containerProvider = new ContainerProvider(),
    ) {
        if ($asyncWorkerCapacity < 1) {
            throw new InvalidArgumentException('Async worker capacity must be positive.');
        }
        if (($sourceId === null) !== ($sourceRevision === null) || $sourceId === '' || $sourceRevision === '') {
            throw new InvalidArgumentException('Prepared configuration source identity and revision must be provided together.');
        }
        $this->asyncWorkerCapacity = $asyncWorkerCapacity;
        $this->timeout = $timeout ?? new TimeoutPolicy();
        $this->sourceId = $sourceId;
        $this->sourceRevision = $sourceRevision;
        $indexedTasks = [];
        foreach ($tasks as $task) {
            if (!$task instanceof TaskDefinition) {
                throw new InvalidArgumentException('Prepared configuration contains an invalid task.');
            }
            if (isset($indexedTasks[(string) $task->id])) {
                throw new InvalidArgumentException('Duplicate task ID: ' . $task->id);
            }
            if ($task->mode === ExecutionMode::SYNC && $task->timeout !== null) {
                throw new InvalidArgumentException('Synchronous tasks cannot have a timeout.');
            }
            $indexedTasks[(string) $task->id] = $task;
        }
        $this->tasks = $indexedTasks;
        ScalarMetadata::assertAggregateSupported($this->projection());
    }

    public function __serialize(): array
    {
        throw new LogicException('Prepared configurations cannot be serialized.');
    }

    public function task(TaskId|string $id): TaskDefinition
    {
        return $this->tasks[(string) $id] ?? throw new InvalidArgumentException('Unknown task: ' . $id);
    }

    public static function canonical(mixed $value): string
    {
        return Canonicalizer::value($value);
    }

    /**
     * Assert the strict, command-free policy accepted by an asynchronous worker.
     * This is intentionally independent of any process-local task objects.
     *
     * @param mixed $policy
     */
    public static function assertTaskPolicyProjection(mixed $policy): void
    {
        if (!is_array($policy)
            || array_keys($policy) !== ['format', 'source', 'task']
            || $policy['format'] !== self::TASK_FORMAT
            || !is_array($policy['source'])
            || array_keys($policy['source']) !== [0, 1]
            || !is_array($policy['task'])
            || array_keys($policy['task']) !== ['id', 'mode', 'timeout', 'metadata', 'trigger', 'overlap', 'missed']) {
            throw new InvalidArgumentException('Task policy projection shape is invalid.');
        }

        $source = $policy['source'];
        if (($source[0] === null) !== ($source[1] === null)
            || ($source[0] !== null && (!is_string($source[0]) || $source[0] === ''))
            || ($source[1] !== null && (!is_string($source[1]) || $source[1] === ''))) {
            throw new InvalidArgumentException('Task policy source identity is invalid.');
        }
        // An absent source is valid for an unbound local configuration. When
        // present, source IDs may be paths but retain portable scalar bounds.
        if ($source[0] !== null) {
            ScalarMetadata::assertAggregateSupported($source[0], $source[1]);
        }

        $task = $policy['task'];
        if (!is_string($task['id']) || !is_string($task['mode']) || !in_array($task['mode'], ['async', 'sync'], true)
            || !is_string($task['overlap'])
            || !in_array($task['overlap'], ['allow', 'skip'], true)
            || !is_array($task['metadata']) || !is_array($task['trigger']) || array_keys($task['trigger']) !== [0, 1]
            || !is_string($task['trigger'][0]) || !is_array($task['trigger'][1])
            || !is_array($task['missed']) || array_keys($task['missed']) !== [0, 1]
            || !is_string($task['missed'][0]) || !in_array($task['missed'][0], ['skip', 'all', 'last'], true)
            || ($task['missed'][1] !== null && !is_int($task['missed'][1]))) {
            throw new InvalidArgumentException('Task policy projection scalar shape is invalid.');
        }
        if ($task['missed'][0] === 'all' && ($task['missed'][1] === null || $task['missed'][1] < 1)) {
            throw new InvalidArgumentException('Task policy missed occurrence limit is invalid.');
        }
        if ($task['missed'][0] !== 'all' && $task['missed'][1] !== null) {
            throw new InvalidArgumentException('Task policy missed occurrence limit is invalid.');
        }

        $timeout = $task['timeout'];
        if ($task['mode'] === 'sync' && $timeout !== null) {
            throw new InvalidArgumentException('Synchronous task policy timeout is invalid.');
        }
        if ($timeout !== null) {
            if (!is_array($timeout) || array_keys($timeout) !== [0, 1]
                || ($timeout[0] === null && $timeout[1] === null)) {
                throw new InvalidArgumentException('Task policy timeout is invalid.');
            }
            foreach ($timeout as $value) {
                if ($value !== null && (!is_float($value) || !is_finite($value) || $value <= 0)) {
                    throw new InvalidArgumentException('Task policy timeout is invalid.');
                }
            }
            if ($timeout[0] !== null && $timeout[1] !== null && $timeout[1] < $timeout[0]) {
                throw new InvalidArgumentException('Task policy timeout ordering is invalid.');
            }
        }

        // The constructors below enforce the same portable Identifier and
        // standard trigger contracts used by process-local configuration.
        new TaskId($task['id']);
        if (array_key_exists('schedule', $task)) {
            throw new InvalidArgumentException('Legacy schedule-bearing task policy is unsupported.');
        }
        ScalarMetadata::assertAggregateSupported($task['metadata'], $task['trigger'][1]);
        (new TriggerDefinition($task['trigger'][0], $task['trigger'][1]))->validate();
    }

    public function timeoutFor(TaskDefinition $task): ?TimeoutPolicy
    {
        if ($task->mode !== ExecutionMode::ASYNC) {
            return null;
        }

        $effective = $this->timeout->merge($task->timeout);
        return $effective->enforced() ? $effective : null;
    }

    public function withSource(string $sourceId, string $sourceRevision): self
    {
        return new self(array_values($this->tasks), $this->timeout, $sourceId, $sourceRevision, $this->asyncWorkerCapacity, $this->commandExecutorMap, $this->containerProvider);
    }

    public function fingerprint(): string
    {
        return hash('sha256', Canonicalizer::value($this->projection()));
    }

    /** The sole command-free task projection used by admission, workers, and the ledger. */
    public function taskPolicyProjection(TaskDefinition $task): array
    {
        $timeout = $this->timeoutFor($task);
        ScalarMetadata::assertAggregateSupported($task->metadata, $task->trigger->definition);
        return [
            'format' => self::TASK_FORMAT,
            'source' => [$this->sourceId, $this->sourceRevision],
            'task' => [
                'id' => (string) $task->id,
                'mode' => $task->mode->value,
                'timeout' => $timeout === null ? null : [$timeout->softSeconds, $timeout->hardSeconds],
                'metadata' => $task->metadata,
                'trigger' => [$task->trigger->reference, $task->trigger->definition],
                'overlap' => $task->overlap->value,
                'missed' => [$task->missedOccurrences->policy->value, $task->missedOccurrences->max],
            ],
        ];
    }

    /** Fingerprint of the transport-safe task policy, deliberately excluding command values. */
    public function taskFingerprint(TaskDefinition $task): string
    {
        return hash('sha256', Canonicalizer::value($this->taskPolicyProjection($task)));
    }

    /** @return array<string,mixed> */
    private function projection(): array
    {
        $tasks = [];
        foreach ($this->tasks as $id => $task) {
            $timeout = $this->timeoutFor($task);
            $tasks[$id] = [
                'id' => (string) $task->id,
                'mode' => $task->mode->value,
                'timeout' => $timeout === null ? null : [$timeout->softSeconds, $timeout->hardSeconds],
                'metadata' => $task->metadata,
                'trigger' => [$task->trigger->reference, $task->trigger->definition],
                'missed' => [$task->missedOccurrences->policy->value, $task->missedOccurrences->max],
                'overlap' => $task->overlap->value,
            ];
        }
        return ['format' => self::TASK_FORMAT, 'source' => [$this->sourceId, $this->sourceRevision], 'timeout' => [$this->timeout->softSeconds, $this->timeout->hardSeconds], 'asyncWorkerCapacity' => $this->asyncWorkerCapacity, 'tasks' => $tasks];
    }
}
