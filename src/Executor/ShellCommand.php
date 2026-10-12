<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use LogicException;
use Yiisoft\Scheduler\ScalarMetadata;

/** An immutable direct executable and argument vector. */
final class ShellCommand implements Command
{
    /** @param array<mixed, mixed> $argv @param array<mixed, mixed> $variables */
    public function __construct(
        public readonly string $executable,
        public readonly array $argv = [],
        public readonly array $variables = [],
    ) {
        ScalarMetadata::assertAggregateSupported($executable, $argv, $variables);
    }

    public function __serialize(): array
    {
        throw new LogicException('Shell commands cannot be serialized.');
    }

    public function specification(): ShellCommandSpecification
    {
        return new ShellCommandSpecification($this->executable, $this->argv, $this->variables);
    }
}
