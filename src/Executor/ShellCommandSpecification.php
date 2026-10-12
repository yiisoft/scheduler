<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use InvalidArgumentException;
use Yiisoft\Scheduler\ScalarMetadata;

use function is_int;
use function is_string;
use function preg_match;
use function strlen;
use function strpos;
use function str_contains;

/** An immutable, literal executable and argv specification. */
final class ShellCommandSpecification
{
    /**
     * @param array<mixed, mixed> $argv
     * @param array<mixed, mixed> $variables
     */
    public function __construct(
        public readonly string $executable,
        public readonly array $argv = [],
        public readonly array $variables = [],
    ) {
        if ($executable === '' || str_contains($executable, "\0")) {
            throw new InvalidArgumentException('Executable must be non-empty and contain no NUL bytes.');
        }
        self::validateString($executable);
        $expectedKey = 0;
        foreach ($argv as $key => $argument) {
            if (!is_int($key) || $key !== $expectedKey++ || !is_string($argument)) {
                throw new InvalidArgumentException('Arguments must be an ordered list of strings.');
            }
            self::validateString($argument);
            self::validatePlaceholders($argument);
        }
        foreach ($variables as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1 || !is_string($value)) {
                throw new InvalidArgumentException('Variables must be a map of valid names to strings.');
            }
            self::validateString($name);
            self::validateString($value);
        }
        ScalarMetadata::assertAggregateSupported($executable, $argv, $variables);
    }

    private static function validateString(string $value): void
    {
        if (str_contains($value, "\0") || strlen($value) > 65536) {
            throw new InvalidArgumentException('Shell command strings are invalid or oversized.');
        }
    }

    private static function validatePlaceholders(string $value): void
    {
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] === '\\' && $i + 2 < $length && $value[$i + 1] === '$' && $value[$i + 2] === '{') {
                $end = strpos($value, '}', $i + 3);
                if ($end === false || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', substr($value, $i + 3, $end - $i - 3)) !== 1) {
                    throw new InvalidArgumentException('Malformed shell variable placeholder.');
                }
                $i = $end;
                continue;
            }
            if ($value[$i] !== '$') {
                continue;
            }
            if ($i + 1 >= $length || $value[$i + 1] !== '{') {
                throw new InvalidArgumentException('Malformed shell variable placeholder.');
            }
            $end = strpos($value, '}', $i + 2);
            if ($end === false || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', substr($value, $i + 2, $end - $i - 2)) !== 1) {
                throw new InvalidArgumentException('Malformed shell variable placeholder.');
            }
            $i = $end;
        }
    }
}
