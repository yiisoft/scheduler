<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Binary;

use InvalidArgumentException;
use Yiisoft\Schedule\Executor\ShellVariableOverrides;

use function count;
use function is_string;

/** Strict parser for the deliberately small worker-only CLI contract. */
final class WorkerArgumentParser
{
    public function parse(array $argv): WorkerInvocation
    {
        if (count($argv) < 2 || !is_string($argv[0]) || $argv[0] !== 'run' || !is_string($argv[1])
            || $argv[1] === '' || str_starts_with($argv[1], '-')) {
            throw new InvalidArgumentException('Expected: run <task-id> [worker options].');
        }

        $result = ['task' => $argv[1], 'config' => '', 'source' => '', 'state' => ''];
        $seen = [];
        $overrideArguments = [];
        for ($i = 2, $n = count($argv); $i < $n; $i++) {
            $arg = $argv[$i] ?? null;
            if (!is_string($arg)) {
                throw new InvalidArgumentException('Unknown or misplaced worker option.');
            }
            if ($arg === '--var' || str_starts_with($arg, '--var=')) {
                $overrideArguments[] = $arg;
                if ($arg === '--var') {
                    $overrideArguments[] = $argv[++$i] ?? null;
                }
                continue;
            }
            /** @var 'config'|'source'|'state' $name */
            $name = match ($arg) {
                '--config' => 'config',
                '--source' => 'source',
                '--state' => 'state',
                default => throw new InvalidArgumentException('Unknown or misplaced worker option.'),
            };
            $value = $argv[$i + 1] ?? null;
            if (isset($seen[$name]) || !is_string($value) || $value === '' || str_starts_with($value, '-')) {
                throw new InvalidArgumentException('Duplicate or missing worker option.');
            }
            $seen[$name] = true;
            $result[$name] = $value;
            $i++;
        }
        if (!isset($result['config'], $result['source'], $result['state'])
            || $result['config'] === '' || $result['source'] === '' || $result['state'] === '') {
            throw new InvalidArgumentException('Missing worker option.');
        }
        return new WorkerInvocation(
            $result['task'],
            $result['config'],
            $result['source'],
            $result['state'],
            ShellVariableOverrides::fromArguments($overrideArguments),
        );
    }
}
