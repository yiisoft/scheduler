<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

/** Outcome of coordinating admission, reservation, and an optional launch. */
final class ExecutionIntegrationDispatchResult
{
    public function __construct(
        public readonly ?ExecutionAdmission $admission,
        public readonly ?ExecutionDispatchResult $dispatch,
        public readonly ?ExecutionWorkerLaunchHandle $launchHandle,
    ) {}

    public function hasWork(): bool
    {
        return $this->admission !== null;
    }
}
