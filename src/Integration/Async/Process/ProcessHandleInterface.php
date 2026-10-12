<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async\Process;

interface ProcessHandleInterface
{
    public function isRunning(): bool;

    /** True only after the worker and every process in its owned scope have exited. */
    public function isQuiescent(): bool;

    public function poll(): ?ProcessResult;

    public function terminateSoft(): void;

    public function terminateHard(): void;
}
