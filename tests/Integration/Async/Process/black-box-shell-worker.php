<?php

declare(strict_types=1);

require \dirname(__DIR__, 4) . '/vendor/autoload.php';

use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerEntrypoint;
use Yiisoft\Scheduler\Tests\Support\{BlackBoxClock,BlackBoxLedgerLocator,BlackBoxShellRuntimeFactory};

$capability = stream_get_contents(\STDIN);
$result = (new ExecutionWorkerEntrypoint(new BlackBoxLedgerLocator(), new BlackBoxShellRuntimeFactory(), new BlackBoxClock()))->invoke($capability, 3000);
fwrite(\STDERR, $result->status . "\n");
exit(\in_array($result->status, ['committed-succeeded'], true) ? 0 : 1);
