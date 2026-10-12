<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Binary;

use InvalidArgumentException;

use function array_slice;
use function count;
use function is_string;

/** Parses top-level modes before any configuration source is opened. */
final class CliArgumentParser
{
    public function parse(array $arguments): CliInvocation
    {
        $first = $arguments[0] ?? null;
        if ($first === 'run') {
            // WorkerArgumentParser owns the complete worker grammar.
            return new CliInvocation('run', '', null);
        }
        if ($first === 'healthcheck') {
            return $this->parseOptions(array_slice($arguments, 1), 'healthcheck');
        }
        if ($first !== null && is_string($first) && !str_starts_with($first, '-')) {
            throw new InvalidArgumentException("Unsupported command: {$first}");
        }
        return $this->parseOptions($arguments, 'planner');
    }

    private function parseOptions(array $arguments, string $mode): CliInvocation
    {
        $config = null;
        $state = null;
        $seen = [];
        for ($i = 0, $n = count($arguments); $i < $n; ++$i) {
            $argument = $arguments[$i] ?? null;
            if (!is_string($argument)) {
                throw new InvalidArgumentException('Unknown or misplaced scheduler option.');
            }
            $name = null;
            $value = null;
            if (str_starts_with($argument, '--config=')) {
                $name = 'config';
                $value = substr($argument, 9);
            } elseif (str_starts_with($argument, '--state-dir=')) {
                $name = 'state';
                $value = substr($argument, 12);
            } elseif ($argument === '--config' || $argument === '--state-dir') {
                $name = $argument === '--config' ? 'config' : 'state';
                $value = $arguments[++$i] ?? null;
            } else {
                throw new InvalidArgumentException('Unknown or misplaced scheduler option.');
            }
            if (isset($seen[$name]) || !is_string($value) || $value === '' || str_starts_with($value, '-')) {
                throw new InvalidArgumentException('Duplicate or missing scheduler option.');
            }
            $seen[$name] = true;
            if ($name === 'config') {
                $config = $value;
            } else {
                $state = $value;
            }
        }
        if ($config === null) {
            throw new InvalidArgumentException("Expected {$mode} options: --config <path>.");
        }
        return new CliInvocation($mode, $config, $state);
    }
}
