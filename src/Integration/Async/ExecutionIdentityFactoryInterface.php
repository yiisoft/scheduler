<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

/** Narrow seam for producing a validated execution identity. */
interface ExecutionIdentityFactoryInterface
{
    public function create(string $sourceId, string $revision, string $taskKey, string $occurrenceId): ExecutionIdentity;
}
