<?php

declare(strict_types=1);

require \dirname(__DIR__, 4) . '/vendor/autoload.php';
use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerEntrypoint;
use Yiisoft\Scheduler\Tests\Support\{BlackBoxClock,BlackBoxLedgerLocator,BlackBoxRuntimeFactory};

$mode = getenv('BLACKBOX_MODE');
if ($mode === 'exit-before-entrypoint') {
    exit(0);
}

$capability = stream_get_contents(\STDIN);
$result = (new ExecutionWorkerEntrypoint(new BlackBoxLedgerLocator(), new BlackBoxRuntimeFactory(), new BlackBoxClock()))->invoke($capability, 3000);
fwrite(\STDERR, $result->status . "\n");
exit($result->status === 'committed-succeeded' ? 0 : 1);
