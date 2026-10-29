<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use RuntimeException;
use Yiisoft\Scheduler\Integration\Async\ExecutionLedgerCommandInterface;
use Yiisoft\Scheduler\Integration\Async\ExecutionLedgerLocatorInterface;

use function getenv;
use function is_string;

final class BlackBoxLedgerLocator implements ExecutionLedgerLocatorInterface
{
    public function resolve(string $locator): ExecutionLedgerCommandInterface
    {
        if ($locator !== BlackBoxApplication::LEDGER_LOCATOR) {
            throw new RuntimeException('unlisted fixture locator');
        }
        $path = getenv('BLACKBOX_LEDGER_PATH');
        if (!is_string($path) || $path === '') {
            throw new RuntimeException('fixture ledger path unavailable');
        }
        return SQLiteLedgerFixture::ledger($path);
    }
}
