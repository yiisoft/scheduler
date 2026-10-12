<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async\Store;

/** Writes one complete snapshot and commits it atomically. */
interface FileExecutionLedgerSnapshotWriter
{
    public function write(string $path, string $data): void;
}
