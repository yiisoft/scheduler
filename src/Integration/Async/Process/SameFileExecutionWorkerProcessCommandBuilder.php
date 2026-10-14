<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use Yiisoft\Scheduler\Executor\{PhpProcessConfiguration,ShellVariableOverrides};
use Yiisoft\Scheduler\Integration\Async\{ExecutionWorkerLaunchRequest,SystemMonotonicClock};
use Yiisoft\Scheduler\Integration\Async\MonotonicClockInterface;

use function assert;
use function getenv;
use function is_string;

/** Builds the package's same-file worker command; the capability stays on stdin. */
final class SameFileExecutionWorkerProcessCommandBuilder implements ExecutionWorkerProcessCommandBuilderInterface
{
    public function __construct(
        private readonly string $executable,
        private readonly string $configuration,
        private readonly string $source,
        private readonly string $state,
        private readonly ?string $cwd = null,
        private readonly ShellVariableOverrides $overrides = new ShellVariableOverrides(),
        private readonly MonotonicClockInterface $clock = new SystemMonotonicClock(),
        /** @var array<string, string>|null */
        private readonly ?array $environment = null,
        private readonly ?PhpProcessConfiguration $phpConfiguration = null,
    ) {}

    public function build(ExecutionWorkerLaunchRequest $request): ProcessLaunchRequest
    {
        $capability = $request->capability;
        $arguments = ['run', $capability->occurrence->taskKey, '--config', $this->configuration, '--source', $this->source, '--state', $this->state];
        foreach ($this->overrides->values as $name => $value) {
            assert(is_string($name) && is_string($value));
            $arguments[] = '--var';
            $arguments[] = $name . '=' . $value;
        }
        $executable = $this->executable;
        if ($this->phpConfiguration !== null) {
            $arguments = [...$this->phpConfiguration->arguments, $this->executable, ...$arguments];
            $executable = $this->phpConfiguration->binary;
        }
        return new ProcessLaunchRequest(
            $executable,
            $arguments,
            $this->cwd,
            $this->environment ?? $this->inheritedEnvironment(),
            $capability->encode(),
        );
    }

    /** @return array<string, string> */
    private function inheritedEnvironment(): array
    {
        /** @var array<string, string> $environment */
        $environment = getenv();

        return $environment;
    }
}
