<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use Closure;
use InvalidArgumentException;
use Yiisoft\Scheduler\Config\{PreparedConfiguration,RuntimeConfigurationSourceInterface};
use Yiisoft\Scheduler\Executor\{CallableCommand,CallableExecutor,CommandExecutorRuntimeFactory,ExecutionContext,ExecutorInterface,PhpCommand,ShellCommand,ShellCommandExecutor};
use Yiisoft\Scheduler\ExecutionMode;
use Yiisoft\Scheduler\Executor\Command;
use Yiisoft\Scheduler\Integration\Async\Process\ExecutionOwnedCommandLauncher;

use function array_key_exists;
use function hash;
use function is_string;
use function preg_match;
use function strlen;
use function str_contains;
use function is_array;
use function is_float;

/** Composes a fresh, process-local runtime after a fenced claim. */
final class PreparedConfigurationExecutionWorkerRuntimeFactory implements ExecutionWorkerRuntimeFactoryInterface, ExecutionWorkerPreClaimValidatorInterface
{
    public function __construct(
        private readonly RuntimeConfigurationSourceInterface $source,
        private readonly CommandExecutorRuntimeFactory $runtimeFactory,
        private readonly ?ShellCommandRuntimeFactoryInterface $shellRuntimeFactory = null,
    ) {}

    public function validate(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $observedRecord): void
    {
        $admittedRevision = $this->assertRevision($observedRecord->sourceRevision);
        $admittedSourceId = $this->assertSourceId($observedRecord->sourceIdentity);
        if ($capability->sourceRevision !== $admittedRevision
            || ($capability->policy['source'][0] ?? null) !== $admittedSourceId
            || ($capability->policy['source'][1] ?? null) !== $admittedRevision
            || $this->source->sourceId() !== $admittedSourceId
            || $this->source->revision() !== $admittedRevision) {
            throw new InvalidArgumentException('Worker source facts do not match the canonical record.');
        }
    }

    public function create(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $claimedRecord, ?ExecutionLedgerCommandInterface $ledger = null): ExecutionWorkerRuntimeInterface
    {
        if (!$claimedRecord->occurrence->equals($capability->occurrence)
            || $claimedRecord->correlation->attemptId !== $capability->attemptId
            || $claimedRecord->correlation->runId !== $capability->runId
            || $claimedRecord->correlation->executionId !== $capability->executionId
            || !$claimedRecord->fence->equals(new ExecutionFence($capability->generation, $capability->fenceToken))
            || $claimedRecord->configurationFingerprint !== $capability->configurationFingerprint
            || hash('sha256', PreparedConfiguration::canonical($claimedRecord->taskSnapshot)) !== $capability->taskVersion
            || $claimedRecord->taskSnapshot !== $capability->policy) {
            throw new InvalidArgumentException('Worker claim identity does not match its capability.');
        }

        $sourceId = $this->source->sourceId();
        $claimedSourceId = $this->assertSourceId($claimedRecord->sourceIdentity);
        $claimedRevision = $this->assertRevision($claimedRecord->sourceRevision);
        if ($claimedSourceId !== $sourceId
            || $claimedRevision !== $capability->sourceRevision
            || ($capability->policy['source'][0] ?? null) !== $claimedSourceId
            || ($capability->policy['source'][1] ?? null) !== $claimedRevision
            || $this->source->revision() !== $claimedRevision) {
            throw new InvalidArgumentException('Claimed worker source identity or revision does not match.');
        }

        $configuration = $this->prepare();
        if ($sourceId !== $this->source->sourceId()
            || $configuration->sourceId !== $sourceId
            || $configuration->sourceRevision !== $this->source->revision()
            || $configuration->sourceRevision !== $capability->sourceRevision) {
            throw new InvalidArgumentException('Worker source identity changed after loading.');
        }
        $task = $configuration->task($capability->occurrence->taskKey);
        if ($task->mode !== ExecutionMode::ASYNC) {
            throw new InvalidArgumentException('Worker selected a synchronous task.');
        }
        $command = $task->command;
        $configurationFingerprint = $configuration->fingerprint();
        $taskFingerprint = $configuration->taskFingerprint($task);
        $taskPolicy = $configuration->taskPolicyProjection($task);
        if ($configurationFingerprint !== $capability->configurationFingerprint || $taskFingerprint !== $capability->taskVersion || $taskPolicy !== $capability->policy) {
            throw new InvalidArgumentException('Worker source, configuration fingerprint, or task identity changed.');
        }

        $map = $configuration->commandExecutorMap;
        if ($map === null) {
            throw new InvalidArgumentException('Worker command executor map is missing.');
        }
        $source = $this->source;
        $revision = $this->assertRevision($configuration->sourceRevision);
        // The built-in shell/php executor is the only runtime that receives
        // the nested ownership boundary. Application replacements retain
        // their original launcher and synchronous/local behavior.
        $shellRuntimeFactory = $this->shellRuntimeFactory;
        if ($ledger !== null && $shellRuntimeFactory instanceof PreparedShellCommandRuntimeFactory) {
            $shellRuntimeFactory = $shellRuntimeFactory->withLauncher(new ExecutionOwnedCommandLauncher(
                $ledger,
                $capability,
                $claimedRecord,
                $capability->launchDescriptor ?? throw new InvalidArgumentException('Worker launch descriptor is missing.'),
                $shellRuntimeFactory->launcher(),
            ));
        }
        $guard = static fn(): bool => $source->sourceId() === $sourceId && $source->revision() === $revision;
        /** @var array<class-string<Command>, ExecutorInterface> $overlay */
        $overlay = [];
        $configured = $map->all();
        // A prepared map may contain an application replacement for either built-in
        // command. Only refresh the process-local built-ins; never discard a
        // replacement reconstructed by the worker's runtime factory.
        if (!array_key_exists(CallableCommand::class, $configured) || $configured[CallableCommand::class] instanceof CallableExecutor) {
            $overlay[CallableCommand::class] = new CallableExecutor($configuration->containerProvider, $guard);
        }
        if ($shellRuntimeFactory !== null
            && (!array_key_exists(ShellCommand::class, $configured) || $configured[ShellCommand::class] instanceof ShellCommandExecutor)) {
            $overlay[ShellCommand::class] = new ShellCommandExecutor($shellRuntimeFactory);
        }
        if ($shellRuntimeFactory !== null
            && (!array_key_exists(PhpCommand::class, $configured) || $configured[PhpCommand::class] instanceof ShellCommandExecutor)) {
            $overlay[PhpCommand::class] = new ShellCommandExecutor($shellRuntimeFactory);
        }
        $map = $map->with($overlay);
        $executor = $map->executorFor($command);
        $context = new ExecutionContext($command, $configuration->timeoutFor($task), null, $task->metadata);
        $policyTimeout = $this->policyTimeout($taskPolicy);
        $beforeInvocation = static function () use ($source, $sourceId, $claimedSourceId, $revision, $configuration, $task, $configurationFingerprint, $taskFingerprint, $taskPolicy, $policyTimeout, $capability): void {
            if ($source->sourceId() !== $sourceId || $source->sourceId() !== $claimedSourceId) {
                throw new InvalidArgumentException('Worker source identity changed before invocation.');
            }
            if ($source->revision() !== $revision) {
                throw new InvalidArgumentException('Worker source revision changed before invocation.');
            }
            if ($configuration->sourceId !== $sourceId || $configuration->fingerprint() !== $configurationFingerprint || $configuration->taskFingerprint($task) !== $taskFingerprint || $configuration->taskPolicyProjection($task) !== $taskPolicy || $capability->policy !== $taskPolicy) {
                throw new InvalidArgumentException('Worker configuration, policy, or task fingerprint changed before invocation.');
            }
            $effectiveTimeout = $configuration->timeoutFor($task);
            $expectedTimeout = $effectiveTimeout === null ? null : [$effectiveTimeout->softSeconds, $effectiveTimeout->hardSeconds];
            if ($expectedTimeout !== $policyTimeout) {
                throw new InvalidArgumentException('Worker async policy changed before invocation.');
            }
        };

        return new class ($executor, $context, $beforeInvocation) implements ExecutionWorkerRuntimeInterface {
            public function __construct(private readonly ExecutorInterface $executor, private readonly ExecutionContext $context, private readonly Closure $beforeInvocation) {}

            public function execute(): ExecutionOutcome
            {
                ($this->beforeInvocation)();
                return $this->executor->execute($this->context);
            }
        };
    }

    private function prepare(): PreparedConfiguration
    {
        $before = $this->source->revision();
        $configuration = $this->source->prepare($this->runtimeFactory);
        if ($before !== $this->source->revision()) {
            throw new InvalidArgumentException('Worker source revision changed while loading.');
        }
        return $configuration;
    }

    /** @param array<array-key, mixed> $policy
     *  @return array{0: float|null, 1: float|null}|null
     */
    private function policyTimeout(array $policy): ?array
    {
        PreparedConfiguration::assertTaskPolicyProjection($policy);
        $task = $policy['task'] ?? null;
        if (!is_array($task) || !array_key_exists('timeout', $task)) {
            throw new InvalidArgumentException('Worker task policy is invalid.');
        }
        $timeout = $task['timeout'];
        if ($timeout === null) {
            return null;
        }
        if (!is_array($timeout) || array_keys($timeout) !== [0, 1]
            || (($timeout[0] !== null) && !is_float($timeout[0]))
            || (($timeout[1] !== null) && !is_float($timeout[1]))) {
            throw new InvalidArgumentException('Worker task policy timeout is invalid.');
        }
        /** @var array{0: float|null, 1: float|null} $timeout */
        return $timeout;
    }

    private function assertRevision(mixed $revision): string
    {
        if (!is_string($revision) || $revision === '' || strlen($revision) > 512 || str_contains($revision, "\0")) {
            throw new InvalidArgumentException('Worker source revision is invalid.');
        }
        return $revision;
    }

    private function assertSourceId(mixed $sourceId): string
    {
        if (!is_string($sourceId) || $sourceId === '' || strlen($sourceId) > 4096 || preg_match('/[\\x00-\\x1F\\x7F]/', $sourceId) === 1) {
            throw new InvalidArgumentException('Worker source identity is invalid.');
        }
        return $sourceId;
    }
}
