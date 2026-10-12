<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Binary;

use InvalidArgumentException;
use Yiisoft\Schedule\Executor\ShellVariableOverrides;

use function strlen;

/** Immutable worker command-line metadata; transport maps remain at the CLI edge. */
final class WorkerInvocation
{
    private const MAX_IDENTIFIER_LENGTH = 128;
    private const MAX_PATH_LENGTH = 4096;

    public function __construct(
        public readonly string $task,
        public readonly string $config,
        public readonly string $source,
        public readonly string $state,
        public readonly ShellVariableOverrides $overrides,
    ) {
        foreach ([[$task, self::MAX_IDENTIFIER_LENGTH], [$config, self::MAX_PATH_LENGTH], [$source, self::MAX_PATH_LENGTH], [$state, self::MAX_PATH_LENGTH]] as [$value, $maxLength]) {
            if ($value === '' || strlen($value) > $maxLength || str_starts_with($value, '-') || str_contains($value, "\0")) {
                throw new InvalidArgumentException('Worker invocation contains an invalid option.');
            }
        }
    }
}
