<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Executor;

use InvalidArgumentException;
use Yiisoft\Schedule\ScalarMetadata;

use function array_key_exists;
use function is_string;
use function preg_match;
use function strlen;
use function strpos;
use function substr;

final class ShellVariableResolver
{
    /**
     * Resolve argv only; environment is supplied explicitly for deterministic use.
     *
     * @param array<mixed, mixed> $environment
     */
    public function resolve(ShellCommandSpecification $command, ShellVariableOverrides $overrides, array $environment = []): ResolvedShellCommand
    {
        /** @var array<string, string> $values */
        $values = [];
        foreach ($command->variables as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $values[$name] = $value;
            }
        }
        foreach ($environment as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $values[$name] = $value;
            }
        }
        foreach ($overrides->values as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $values[$name] = $value;
            }
        }
        ScalarMetadata::assertAggregateSupported($values);
        $argv = [];
        $outputBytes = 0;
        foreach ($command->argv as $argument) {
            if (!is_string($argument)) {
                throw new InvalidArgumentException('Arguments must be an ordered list of strings.');
            }
            $resolved = $this->replace($argument, $values);
            $outputBytes += strlen($resolved);
            if ($outputBytes > 1048576) {
                throw new InvalidArgumentException('Resolved shell arguments exceed the aggregate size limit.');
            }
            $argv[] = $resolved;
        }
        return new ResolvedShellCommand($command->executable, $argv);
    }

    /** @param array<string, string> $values */
    private function replace(string $argument, array $values): string
    {
        $result = '';
        $length = strlen($argument);
        for ($i = 0; $i < $length; $i++) {
            if ($argument[$i] === '\\' && $i + 2 < $length && $argument[$i + 1] === '$' && $argument[$i + 2] === '{') {
                $end = strpos($argument, '}', $i + 3);
                if ($end === false || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', substr($argument, $i + 3, $end - $i - 3)) !== 1) {
                    throw new InvalidArgumentException('Malformed shell variable placeholder.');
                }
                $this->append($result, '${' . substr($argument, $i + 3, $end - $i - 3) . '}');
                $i = $end;
                continue;
            }
            if ($argument[$i] === '$' && $i + 1 < $length && $argument[$i + 1] === '{') {
                $end = strpos($argument, '}', $i + 2);
                if ($end === false || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', substr($argument, $i + 2, $end - $i - 2)) !== 1) {
                    throw new InvalidArgumentException('Malformed shell variable placeholder.');
                }
                $name = substr($argument, $i + 2, $end - $i - 2);
                if (!array_key_exists($name, $values)) {
                    throw new InvalidArgumentException('Missing shell variable: ' . $name);
                }
                $this->append($result, $values[$name]);
                $i = $end;
                continue;
            }
            $this->append($result, $argument[$i]);
        }
        if (strlen($result) > 65536) {
            throw new InvalidArgumentException('Resolved shell argument is oversized.');
        }
        return $result;
    }

    private function append(string &$result, string $part): void
    {
        if (strlen($result) + strlen($part) > 65536) {
            throw new InvalidArgumentException('Resolved shell argument is oversized.');
        }
        $result .= $part;
    }
}
