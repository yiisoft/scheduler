<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Planner;

use RuntimeException;
use Throwable;

use function array_keys;
use function bin2hex;
use function count;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function lstat;
use function random_bytes;
use function rename;
use function scandir;
use function sort;
use function strlen;
use function substr;
use function str_contains;
use function str_ends_with;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const LOCK_EX;

/** The compatibility fence for all planner state in a source namespace. */
final class PlannerStateFormat
{
    public const FILE = 'runtime-format.json';
    public const SCHEMA = 'dev';
    private const MAX_SIZE = 4096;

    /** Initialize only a genuinely empty namespace, or validate its marker. */
    public static function initializeOrValidate(string $directory): void
    {
        if (!is_dir($directory)) {
            throw new RuntimeException('Planner source state directory is not a directory.');
        }

        $marker = $directory . DIRECTORY_SEPARATOR . self::FILE;
        $entries = @scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Planner source state directory cannot be read.');
        }
        foreach ($entries as $entry) {
            if (str_starts_with($entry, self::FILE . '.') && str_ends_with($entry, '.tmp')) {
                throw new RuntimeException('Planner state format marker is incompatible.');
            }
        }
        $stat = @lstat($marker);
        if ($stat !== false) {
            if (($stat['mode'] & 0170000) !== 0100000 || !self::matches($marker)) {
                throw new RuntimeException('Planner state format marker is incompatible.');
            }
            return;
        }

        if (count($entries) !== 2) {
            throw new RuntimeException('Planner state format marker is missing.');
        }
        self::write($marker);
    }

    /** Return true only for a regular, compatible marker; never writes. */
    public static function isCompatible(string $directory): bool
    {
        $marker = $directory . DIRECTORY_SEPARATOR . self::FILE;
        $stat = @lstat($marker);
        return $stat !== false
            && ($stat['mode'] & 0170000) === 0100000
            && self::matches($marker);
    }

    /** Distinguish an absent marker from an incompatible or malformed one. */
    public static function healthStatus(string $directory): string
    {
        $marker = $directory . DIRECTORY_SEPARATOR . self::FILE;
        $entries = @scandir($directory);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if (str_starts_with($entry, self::FILE . '.') && str_ends_with($entry, '.tmp')) {
                    return 'incompatible';
                }
            }
        }
        return @lstat($marker) === false
            ? 'missing'
            : (self::isCompatible($directory) ? 'ok' : 'incompatible');
    }

    private static function matches(string $marker): bool
    {
        $stat = @lstat($marker);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || !isset($stat['size']) || $stat['size'] > self::MAX_SIZE) {
            return false;
        }
        $raw = @file_get_contents($marker);
        if (!is_string($raw) || strlen($raw) > self::MAX_SIZE || str_contains($raw, "\0")) {
            return false;
        }
        if (self::hasDuplicateObjectMembers($raw)) {
            return false;
        }
        try {
            $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        if (!is_array($data) || count($data) !== 1) {
            return false;
        }
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['schema']) {
            return false;
        }
        return $data['schema'] === self::SCHEMA;
    }

    private static function hasDuplicateObjectMembers(string $raw): bool
    {
        $seen = [];
        $length = strlen($raw);
        $depth = 0;
        $inString = false;
        $escaped = false;
        $start = 0;

        for ($index = 0; $index < $length; $index++) {
            $character = $raw[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                    $next = $index + 1;
                    while ($next < $length && ($raw[$next] === ' ' || $raw[$next] === "\t" || $raw[$next] === "\r" || $raw[$next] === "\n")) {
                        $next++;
                    }
                    if ($depth === 1 && $next < $length && $raw[$next] === ':') {
                        try {
                            $key = json_decode(substr($raw, $start, $index - $start + 1), true, 1, JSON_THROW_ON_ERROR);
                        } catch (Throwable) {
                            return false;
                        }
                        if (!is_string($key)) {
                            return false;
                        }
                        if (isset($seen[$key])) {
                            return true;
                        }
                        $seen[$key] = true;
                    }
                }
                continue;
            }
            if ($character === '"') {
                $inString = true;
                $start = $index;
            } elseif ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;
            }
        }

        return false;
    }

    private static function write(string $marker): void
    {
        $temporary = $marker . '.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            $contents = json_encode(['schema' => self::SCHEMA], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temporary, $contents, LOCK_EX) === false || !@rename($temporary, $marker)) {
                throw new RuntimeException('Planner state format marker cannot be written.');
            }
        } catch (Throwable $e) {
            @unlink($temporary);
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Planner state format marker cannot be written.', 0, $e);
        }
    }
}
