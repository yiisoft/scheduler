<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use Yiisoft\Scheduler\Config\TimeoutPolicy;
use Yiisoft\Scheduler\ScalarMetadata;

/** Immutable input passed to an executor. */
final class ExecutionContext
{
    public readonly mixed $payload;
    /** @var array<mixed, mixed> */
    public readonly array $metadata;

    /**
     * @param array<mixed, mixed> $metadata
     */
    public function __construct(
        public readonly Command $command,
        public readonly ?TimeoutPolicy $timeout = null,
        mixed $payload = null,
        array $metadata = [],
    ) {
        ScalarMetadata::assertAggregateSupported($payload, $metadata);
        $this->payload = $payload;
        $this->metadata = $metadata;
    }
}
