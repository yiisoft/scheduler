<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use InvalidArgumentException;

use function count;
use function is_string;
use function strlen;

final class ProcessLaunchRequest
{
    public const MAX_PROTOCOL_INPUT_LENGTH = 1048576;

    /** @param list<string> $argv @param array<string,string> $environment */
    public function __construct(public readonly string $executable, public readonly array $argv, public readonly ?string $cwd, public readonly array $environment, public readonly string $protocolInput)
    {
        if ($executable === '' || str_contains($executable, "\0") || ($argv !== [] && array_keys($argv) !== range(0, count($argv) - 1))) {
            throw new InvalidArgumentException('Invalid process request.');
        } foreach ($argv as $v) {
            if (str_contains($v, "\0")) {
                throw new InvalidArgumentException('Invalid process argument.');
            }
        } if ($cwd !== null && ($cwd === '' || !is_dir($cwd))) {
            throw new InvalidArgumentException('Invalid process directory.');
        } foreach ($environment as $k => $v) {
            if (!is_string($k) || !is_string($v) || $k === '' || str_contains($k . "$v", "\0")) {
                throw new InvalidArgumentException('Invalid process environment.');
            }
        } if (str_contains($protocolInput, "\0") || strlen($protocolInput) > self::MAX_PROTOCOL_INPUT_LENGTH) {
            throw new InvalidArgumentException('Invalid process input.');
        }
    }
}
