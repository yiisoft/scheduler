<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use Yiisoft\Db\Sqlite\{Connection, Driver};
use Yiisoft\Scheduler\Integration\Async\Store\SQLiteExecutionLedger;

use function dirname;
use function is_dir;
use function mkdir;

final class SQLiteLedgerFixture
{
    public static function ledger(string $path): SQLiteExecutionLedger
    {
        $database = is_dir($path) ? $path . '/ledger.sqlite' : $path;
        $parent = dirname($database);
        if (!is_dir($parent)) {
            mkdir($parent, 0700, true);
        }
        return new SQLiteExecutionLedger(new Connection(new Driver('sqlite:' . $database)));
    }
}
