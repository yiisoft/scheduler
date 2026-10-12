<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Binary;

use InvalidArgumentException;

use function in_array;
use function strlen;

/** Immutable, validated top-level scheduler invocation. */
final class CliInvocation
{
    private const MAX_PATH_LENGTH = 4096;

    public function __construct(
        public readonly string $mode,
        public readonly string $config,
        public readonly ?string $state,
    ) {
        if (!in_array($mode, ['planner', 'healthcheck', 'run'], true)) {
            throw new InvalidArgumentException('Unsupported scheduler mode.');
        }
        if ($mode === 'run') {
            if ($config !== '' || $state !== null) {
                throw new InvalidArgumentException('Worker invocation cannot contain planner options.');
            }
            return;
        }
        if ($config === '' || strlen($config) > self::MAX_PATH_LENGTH || str_contains($config, "\0")
            || ($state !== null && ($state === '' || strlen($state) > self::MAX_PATH_LENGTH || str_contains($state, "\0")))) {
            throw new InvalidArgumentException('Scheduler invocation contains an invalid option.');
        }
    }
}
