<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Store;

use InvalidArgumentException;
use Yiisoft\Scheduler\Integration\Async\{ExecutionLedgerCommandInterface, ExecutionLedgerLocatorInterface};

use const DIRECTORY_SEPARATOR;

/** Resolves only ledger directories below one canonical, application-owned root. */
final class FileExecutionLedgerLocator implements ExecutionLedgerLocatorInterface
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException('State root is invalid.');
        }
        $this->root = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    public function resolve(string $locator): ExecutionLedgerCommandInterface
    {
        if ($locator === '' || str_contains($locator, "\0") || str_contains($locator, '..') || str_starts_with($locator, '/')) {
            throw new InvalidArgumentException('Ledger locator is not allowed.');
        }
        $path = realpath($this->root . $locator);
        if ($path === false || !is_dir($path) || !str_starts_with($path . DIRECTORY_SEPARATOR, $this->root)) {
            throw new InvalidArgumentException('Ledger locator is outside the state root.');
        }
        return new FileExecutionLedger($path);
    }
}
