<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Store;

use InvalidArgumentException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Scheduler\Integration\Async\{ExecutionLedgerCommandInterface,ExecutionLedgerLocatorInterface};

/** Resolves one explicitly configured SQLite ledger; locators are not paths or DSNs. */
final class SQLiteExecutionLedgerLocator implements ExecutionLedgerLocatorInterface
{
    private readonly SQLiteExecutionLedger $ledger;
    private readonly string $locator;

    public function __construct(ConnectionInterface $connection, string $locator = 'default', ?ExecutionLedgerPayloadCodec $codec = null)
    {
        if ($locator === '') {
            throw new InvalidArgumentException('SQLite ledger locator must not be empty.');
        }
        $this->locator = $locator;
        $this->ledger = new SQLiteExecutionLedger($connection, $codec);
    }

    public function resolve(string $locator): ExecutionLedgerCommandInterface
    {
        if ($locator === '' || $locator !== $this->locator) {
            throw new InvalidArgumentException('SQLite ledger locator is not allow-listed.');
        }
        return $this->ledger;
    }
}
