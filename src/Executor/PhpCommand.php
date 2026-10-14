<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use InvalidArgumentException;
use LogicException;

use function array_is_list;
use function is_string;
use function str_contains;

/** A literal command launched by the current (or explicitly selected) PHP binary. */
final class PhpCommand implements Command
{
    /** @param array<mixed, mixed>|null $phpArguments */
    public function __construct(
        public readonly ShellCommand $command,
        public readonly ?string $phpBinary = null,
        public readonly ?array $phpArguments = null,
    ) {
        if ($phpBinary !== null && ($phpBinary === '' || str_contains($phpBinary, "\0"))) {
            throw new InvalidArgumentException('PHP binary must be non-empty and contain no NUL bytes.');
        }
        if ($phpArguments !== null) {
            if (!array_is_list($phpArguments)) {
                throw new InvalidArgumentException('PHP arguments must be an ordered list of strings.');
            }
            foreach ($phpArguments as $argument) {
                if (!is_string($argument) || str_contains($argument, "\0")) {
                    throw new InvalidArgumentException('PHP arguments must be an ordered list of strings.');
                }
            }
        }
    }

    public function __serialize(): array
    {
        throw new LogicException('PHP commands cannot be serialized.');
    }

    public function resolve(?PhpProcessConfiguration $current = null): ShellCommand
    {
        $current ??= PhpProcessConfiguration::current();
        $binary = $this->phpBinary ?? $current->binary;
        $arguments = $this->phpArguments ?? $current->arguments;
        $specification = $this->command->specification();
        return new ShellCommand($binary, [...$arguments, $specification->executable, ...$specification->argv], $specification->variables);
    }
}
