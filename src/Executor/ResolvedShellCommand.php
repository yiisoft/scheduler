<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Executor;

use InvalidArgumentException;
use Yiisoft\Schedule\ScalarMetadata;

use function is_int;
use function is_string;
use function str_contains;

final class ResolvedShellCommand
{
    /** @param array<mixed, mixed> $argv */
    public function __construct(public readonly string $executable, public readonly array $argv)
    {
        if ($executable === '' || str_contains($executable, "\0")) {
            throw new InvalidArgumentException('Executable must be non-empty and contain no NUL bytes.');
        }
        $expectedKey = 0;
        foreach ($argv as $key => $argument) {
            if (!is_int($key) || $key !== $expectedKey++ || !is_string($argument)) {
                throw new InvalidArgumentException('Arguments must be an ordered list of strings.');
            }
            if (str_contains($argument, "\0")) {
                throw new InvalidArgumentException('Arguments must not contain NUL bytes.');
            }
        }
        ScalarMetadata::assertAggregateSupported($executable, $argv);
    }
}
