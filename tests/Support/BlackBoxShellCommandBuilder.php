<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use Yiisoft\Scheduler\Integration\Async\ExecutionWorkerLaunchRequest;
use Yiisoft\Scheduler\Integration\Async\Process\ExecutionWorkerProcessCommandBuilderInterface;
use Yiisoft\Scheduler\Integration\Async\Process\ProcessLaunchRequest;

use function dirname;

use const PHP_BINARY;

final class BlackBoxShellCommandBuilder implements ExecutionWorkerProcessCommandBuilderInterface
{
    public function __construct(private readonly string $target, private readonly string $ledgerPath, private readonly string $configPath) {}

    public function build(ExecutionWorkerLaunchRequest $request): ProcessLaunchRequest
    {
        return new ProcessLaunchRequest(PHP_BINARY, [$this->target], dirname($this->target, 4), [
            'BLACKBOX_LEDGER_PATH' => $this->ledgerPath,
            'BLACKBOX_SHELL_CONFIG' => $this->configPath,
        ], $request->capability->encode(), $request->descriptor);
    }
}
