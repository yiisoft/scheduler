<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Support;

use RuntimeException;
use Yiisoft\Schedule\Config\TimeoutPolicy;
use Yiisoft\Schedule\Executor\{ShellCommandSpecification,ShellVariableOverrides};
use Yiisoft\Schedule\Integration\Async\{ExecutionLedgerRecord,ExecutionWorkerCapability,ExecutionWorkerRuntimeFactoryInterface,ExecutionWorkerRuntimeInterface,ShellCommandRuntime,SystemMonotonicClock,UsleepPollWaiter};
use Yiisoft\Schedule\Integration\Async\Process\SymfonyProcessLauncher;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/** Application-owned composition for the shell fixture; configuration is not transported in the capability. */
final class BlackBoxShellRuntimeFactory implements ExecutionWorkerRuntimeFactoryInterface
{
    public function create(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $claimedRecord): ExecutionWorkerRuntimeInterface
    {
        $path = getenv('BLACKBOX_SHELL_CONFIG');
        if (!is_string($path)) {
            throw new RuntimeException('shell fixture configuration unavailable');
        }
        $config = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config)) {
            throw new RuntimeException('invalid shell fixture configuration');
        }
        $marker = $config['marker'] ?? null;
        if (!is_string($marker)) {
            throw new RuntimeException('shell fixture marker unavailable');
        }
        file_put_contents($marker, "bootstrapped\n");
        return new ShellCommandRuntime(
            new ShellCommandSpecification($config['executable'], $config['argv'], $config['variables'] ?? []),
            new SymfonyProcessLauncher(),
            new TimeoutPolicy($config['soft'] ?? null, $config['hard'] ?? null),
            new ShellVariableOverrides($config['overrides'] ?? []),
            $config['environment'] ?? [],
            $config['cwd'] ?? null,
            new SystemMonotonicClock(),
            new UsleepPollWaiter(),
        );
    }
}
