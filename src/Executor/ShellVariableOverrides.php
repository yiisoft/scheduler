<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Executor;

use InvalidArgumentException;
use Yiisoft\Schedule\ScalarMetadata;

use function array_is_list;
use function array_key_exists;
use function count;
use function is_string;
use function preg_match;
use function strlen;
use function str_contains;
use function str_starts_with;
use function strpos;
use function substr;

/** Validated ephemeral values supplied by a caller, never part of prepared configuration. */
final class ShellVariableOverrides
{
    /** @param array<mixed, mixed> $values */
    public function __construct(public readonly array $values = [])
    {
        foreach ($values as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new InvalidArgumentException('Shell variable overrides must be string maps.');
            }
            self::validate($name, $value);
        }
        ScalarMetadata::assertAggregateSupported($values);
    }

    /** @param array<mixed, mixed> $arguments */
    public static function fromArguments(array $arguments): self
    {
        if (!array_is_list($arguments)) {
            throw new InvalidArgumentException('Arguments must be an ordered list of strings.');
        }
        foreach ($arguments as $argument) {
            if (!is_string($argument)) {
                throw new InvalidArgumentException('Arguments must be an ordered list of strings.');
            }
        }
        $values = [];
        $aggregateBytes = 0;
        $total = count($arguments);
        for ($i = 0; $i < $total; $i++) {
            $argument = $arguments[$i];
            if ($argument === '--var') {
                $i++;
                if ($i >= $total || !is_string($arguments[$i])) {
                    throw new InvalidArgumentException('Missing --var value.');
                }
                $assignment = $arguments[$i];
            } elseif (is_string($argument) && str_starts_with($argument, '--var=')) {
                $assignment = substr($argument, 6);
            } else {
                continue;
            }
            $separator = strpos($assignment, '=');
            if ($separator === false) {
                throw new InvalidArgumentException('Shell variable must be NAME=value.');
            }
            $name = substr($assignment, 0, $separator);
            $value = substr($assignment, $separator + 1);
            if (array_key_exists($name, $values)) {
                throw new InvalidArgumentException('Duplicate shell variable.');
            }
            self::validate($name, $value);
            $aggregateBytes += strlen($name) + strlen($value);
            if (count($values) >= 10000 || $aggregateBytes > 1048576) {
                throw new InvalidArgumentException('Shell variable overrides exceed the aggregate size limit.');
            }
            $values[$name] = $value;
        }
        return new self($values);
    }

    private static function validate(string $name, string $value): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1 || strlen($name) > 65536 || strlen($value) > 65536 || str_contains($name, "\0") || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Invalid or oversized shell variable.');
        }
    }
}
