<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Support;

use RuntimeException;
use Yiisoft\Schedule\Integration\Async\ExecutionLedgerInterface;
use Yiisoft\Schedule\Integration\Async\ExecutionLedgerLocatorInterface;
use Yiisoft\Schedule\Integration\Async\Store\FileExecutionLedger;

use function getenv;
use function is_dir;
use function is_string;

final class BlackBoxLedgerLocator implements ExecutionLedgerLocatorInterface
{
    public function resolve(string $locator): ExecutionLedgerInterface
    {
        if ($locator !== BlackBoxApplication::LEDGER_LOCATOR) {
            throw new RuntimeException('unlisted fixture locator');
        }
        $path = getenv('BLACKBOX_LEDGER_PATH');
        if (!is_string($path) || !is_dir($path)) {
            throw new RuntimeException('fixture ledger path unavailable');
        }
        return new FileExecutionLedger($path);
    }
}
