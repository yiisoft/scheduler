<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Ledger;

use Yiisoft\Scheduler\Ledger\InMemoryLedger;
use Yiisoft\Scheduler\Ledger\LedgerCommandInterface;
use Yiisoft\Scheduler\Ledger\LedgerQueryInterface;

final class InMemoryLedgerTest extends LedgerContractTestCase
{
    protected function ledger(): LedgerCommandInterface&LedgerQueryInterface
    {
        return new InMemoryLedger();
    }
}
