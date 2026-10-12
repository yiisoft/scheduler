<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Support;

use RuntimeException;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerLaunchRequest;
use Yiisoft\Schedule\Integration\Async\Process\ExecutionWorkerProcessCommandBuilderInterface;
use Yiisoft\Schedule\Integration\Async\Process\ProcessLaunchRequest;

use function dirname;

use const PHP_BINARY;

final class BlackBoxCommandBuilder implements ExecutionWorkerProcessCommandBuilderInterface
{
    public function __construct(private readonly string $target, private readonly string $ledgerPath, private readonly string $markerPath, private readonly string $effectPath, private readonly string $mode = 'entrypoint', private readonly bool $fail = false) {}

    public function build(ExecutionWorkerLaunchRequest $request): ProcessLaunchRequest
    {
        if ($this->fail) {
            throw new RuntimeException('fixture builder failure');
        }
        return new ProcessLaunchRequest(PHP_BINARY, [$this->target], dirname($this->target, 4), [
            'BLACKBOX_LEDGER_PATH' => $this->ledgerPath,
            'BLACKBOX_MARKER_PATH' => $this->markerPath,
            'BLACKBOX_EFFECT_PATH' => $this->effectPath,
            'BLACKBOX_MODE' => $this->mode,
        ], $request->capability->encode());
    }
}
