<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use InvalidArgumentException;

use function array_is_list;
use function array_slice;
use function escapeshellarg;
use function file_get_contents;
use function getmypid;
use function in_array;
use function is_readable;
use function is_string;
use function preg_match;
use function realpath;
use function rtrim;
use function shell_exec;
use function str_contains;
use function strlen;
use function trim;

use const PHP_BINARY;
use const PHP_OS_FAMILY;

/** Immutable PHP executable and startup-option prefix for a process. */
final class PhpProcessConfiguration
{
    public readonly string $binary;
    /** @var list<string> */
    public readonly array $arguments;
    private static ?self $current = null;

    /** @param array<mixed, mixed> $arguments */
    public function __construct(string $binary, array $arguments = [])
    {
        if ($binary === '' || str_contains($binary, "\0")) {
            throw new InvalidArgumentException('PHP binary must be non-empty and contain no NUL bytes.');
        }
        if (!array_is_list($arguments)) {
            throw new InvalidArgumentException('PHP arguments must be an ordered list of strings.');
        }
        foreach ($arguments as $argument) {
            if (!is_string($argument) || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('PHP arguments must be an ordered list of strings.');
            }
        }

        /** @var list<string> $arguments */
        $this->binary = $binary;
        $this->arguments = $arguments;
    }

    public static function current(): self
    {
        return self::$current ??= new self(PHP_BINARY, self::currentArguments());
    }

    /** @return list<string> */
    private static function currentArguments(): array
    {
        $script = $_SERVER['SCRIPT_FILENAME'] ?? null;
        if (!is_string($script)) {
            return [];
        }

        $parts = self::currentCommandLine();
        $scriptIndex = self::scriptIndex($parts, $script);

        return $scriptIndex === null ? [] : array_slice($parts, 1, $scriptIndex - 1);
    }

    /** @return list<string> */
    private static function currentCommandLine(): array
    {
        if (is_readable('/proc/self/cmdline')) {
            $contents = @file_get_contents('/proc/self/cmdline');
            if (is_string($contents)) {
                $contents = rtrim($contents, "\0");
                if ($contents !== '') {
                    return str_contains($contents, "\0") ? explode("\0", $contents) : self::parseCommandLine($contents);
                }
            }
        }

        $contents = PHP_OS_FAMILY === 'Windows' ? self::windowsCommandLine() : self::posixCommandLine();

        return is_string($contents) ? self::parseCommandLine($contents) : [];
    }

    /** @psalm-suppress ForbiddenCode */
    private static function posixCommandLine(): ?string
    {
        $processId = getmypid();
        if ($processId === false) {
            return null;
        }

        $contents = @shell_exec('ps -ww -p ' . $processId . ' -o command=');

        return is_string($contents) ? trim($contents) : null;
    }

    /** @psalm-suppress ForbiddenCode */
    private static function windowsCommandLine(): ?string
    {
        $processId = getmypid();
        if ($processId === false) {
            return null;
        }

        $contents = @shell_exec('wmic process where processid=' . $processId . ' get CommandLine /value');
        if (is_string($contents) && preg_match('/^CommandLine=(.*)$/m', $contents, $matches) === 1 && isset($matches[1])) {
            return trim($matches[1]);
        }

        $powershell = '(Get-CimInstance Win32_Process -Filter "ProcessId = ' . $processId . '").CommandLine';
        $contents = @shell_exec('powershell.exe -NoProfile -NonInteractive -Command ' . escapeshellarg($powershell));

        return is_string($contents) ? trim($contents) : null;
    }

    /** @param list<string> $parts */
    private static function scriptIndex(array $parts, string $script): ?int
    {
        $realScript = realpath($script);
        foreach ($parts as $index => $candidate) {
            if ($index === 0) {
                continue;
            }
            if ($candidate === $script) {
                return $index;
            }
            if ($realScript !== false && realpath($candidate) === $realScript) {
                return $index;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function parseCommandLine(string $line): array
    {
        $arguments = [];
        $argument = '';
        $quote = '';
        $hasContent = false;
        $length = strlen($line);

        for ($index = 0; $index < $length; $index++) {
            $character = $line[$index];
            $next = $index + 1 < $length ? $line[$index + 1] : '';
            if ($character === '\\' && $next !== '' && in_array($next, ['\\', '"', "'", ' ', "\t", "\r", "\n"], true)) {
                $argument .= $next;
                $hasContent = true;
                $index++;
                continue;
            }
            if ($quote !== '') {
                if ($character === $quote) {
                    $quote = '';
                } else {
                    $argument .= $character;
                }
                $hasContent = true;
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                $hasContent = true;
                continue;
            }
            if (in_array($character, [' ', "\t", "\r", "\n"], true)) {
                if ($hasContent) {
                    $arguments[] = $argument;
                    $argument = '';
                    $hasContent = false;
                }
                continue;
            }
            $argument .= $character;
            $hasContent = true;
        }

        if ($hasContent) {
            $arguments[] = $argument;
        }

        return $arguments;
    }
}
