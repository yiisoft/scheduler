<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Binary;

use InvalidArgumentException;
use Yiisoft\Schedule\Integration\Async\{ExecutionLedgerLocatorInterface,ExecutionWorkerCapability,ExecutionWorkerEntrypoint,ExecutionWorkerInvocationResult};

use function is_callable;
use function strlen;

/** Strict, application-neutral boundary for a fresh worker process. */
final class WorkerAdapter
{
    private ?WorkerInvocation $launchMetadata = null;

    /**
     * @param callable(string,ExecutionWorkerCapability): bool|null $configValidator
     * @param callable(string,ExecutionWorkerCapability): bool|null $sourceValidator
     */
    public function __construct(
        private readonly ExecutionWorkerEntrypoint $entrypoint,
        private readonly ExecutionLedgerLocatorInterface $ledgerLocator,
        private readonly int $maxInput = 8192,
        private readonly mixed $configValidator = null,
        private readonly mixed $sourceValidator = null,
    ) {
        if ($maxInput < 1) {
            throw new InvalidArgumentException('Worker input limit is invalid.');
        }
    }

    public function launchMetadata(): ?WorkerInvocation
    {
        return $this->launchMetadata;
    }

    /** @param array<int, mixed> $argv */
    public function invoke(array $argv, string $input): ExecutionWorkerInvocationResult
    {
        $metadata = (new WorkerArgumentParser())->parse($argv);
        if (strlen($input) > $this->maxInput || $input === '' || str_contains($input, "\0")) {
            throw new InvalidArgumentException('Worker input is oversized or invalid.');
        }

        // Decode here only to validate transport and metadata. The original bytes are
        // passed on so the entrypoint remains the sole decode/locate/claim owner.
        $capability = ExecutionWorkerCapability::decode($input);
        if ($capability->occurrence->taskKey !== $metadata->task) {
            throw new InvalidArgumentException('Worker capability task does not match the requested task.');
        }
        $this->validate($this->configValidator, $metadata->config, $capability, 'configuration');
        $this->validate($this->sourceValidator, $metadata->source, $capability, 'source');

        // Resolving through the configured allow-listed locator proves the ledger
        // locator is beneath the supplied state root, without claiming it here.
        $this->ledgerLocator->resolve($capability->ledgerLocator);
        $this->launchMetadata = $metadata;
        return $this->entrypoint->invoke($input, null);
    }

    private function validate(mixed $validator, string $value, ExecutionWorkerCapability $capability, string $name): void
    {
        if ($validator !== null && (!is_callable($validator) || ($validator)($value, $capability) !== true)) {
            throw new InvalidArgumentException("Worker {$name} metadata is invalid.");
        }
    }
}
