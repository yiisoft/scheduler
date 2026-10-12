<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Support;

use Closure;
use RuntimeException;
use Yiisoft\Schedule\Integration\Async\ExecutionLedgerRecord;
use Yiisoft\Schedule\Integration\Async\ExecutionOutcome;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerCapability;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerRuntimeFactoryInterface;
use Yiisoft\Schedule\Integration\Async\ExecutionWorkerRuntimeInterface;

use function file_put_contents;
use function getenv;
use function is_string;

final class BlackBoxRuntimeFactory implements ExecutionWorkerRuntimeFactoryInterface
{
    public function create(ExecutionWorkerCapability $capability, ExecutionLedgerRecord $claimedRecord): ExecutionWorkerRuntimeInterface
    {
        $marker = getenv('BLACKBOX_MARKER_PATH');
        $effect = getenv('BLACKBOX_EFFECT_PATH');
        if (!is_string($marker) || !is_string($effect)) {
            throw new RuntimeException('fixture runtime paths unavailable');
        }
        file_put_contents($marker, "bootstrapped\n");
        $callable = BlackBoxApplication::fixtureCallable($effect);
        return new class ($callable) implements ExecutionWorkerRuntimeInterface {
            public function __construct(private readonly Closure $callable) {}

            public function execute(): ExecutionOutcome
            {
                ($this->callable)();
                return new ExecutionOutcome(ExecutionOutcome::SUCCEEDED, 'fixture-complete');
            }
        };
    }
}
