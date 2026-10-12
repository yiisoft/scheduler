<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

/** Resolves an application allow-listed ledger locator. */
interface ExecutionLedgerLocatorInterface
{
    public function resolve(string $locator): ExecutionLedgerInterface;
}
