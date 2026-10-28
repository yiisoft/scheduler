<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Store;

use InvalidArgumentException;
use JsonException;
use Yiisoft\Scheduler\Integration\Async\ExecutionOccurrenceCheckpointInterface;
use Yiisoft\Scheduler\Integration\Async\OccurrenceSkipReason;

use function count;
use function file_get_contents;
use function filesize;
use function flock;
use function fopen;
use function fwrite;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function ltrim;
use function json_decode;
use function json_encode;
use function mkdir;
use function realpath;
use function rename;
use function sprintf;
use function strcmp;
use function strlen;
use function unlink;
use function array_key_exists;
use function array_pop;
use function function_exists;
use function in_array;

use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const LOCK_UN;

/**
 * A durable checkpoint backed by one local filesystem state file.
 *
 * All operations use an exclusive lock and updates replace the state file atomically.
 * This is safe for cooperating processes on one local filesystem only; the lock is
 * not a distributed lock and this implementation must not be used as such.
 *
 * Cursor transitions are an exact expected-state compare-and-swap and must be
 * strictly monotonic: a transition is accepted only when its next cursor is
 * ordered after the expected cursor. Canonical {@code t} timestamps and
 * same-prefix decimal-suffix cursors use numeric ordering; other validated
 * cursors use their portable lexical ordering. Cursor equality remains opaque
 * and exact for compare-and-swap purposes.
 */
final class FileExecutionOccurrenceCheckpoint implements ExecutionOccurrenceCheckpointInterface
{
    private const SCHEMA = 'dev';
    private const MAX_STATE_BYTES = 1_048_576;
    private const MAX_ENTRIES = 10_000;

    private readonly string $statePath;
    private readonly string $lockPath;

    public function __construct(string $stateRoot)
    {
        if ($stateRoot === '' || (!is_dir($stateRoot) && !@mkdir($stateRoot, 0700, true)) || ($root = realpath($stateRoot)) === false) {
            throw new InvalidArgumentException('Checkpoint state root is invalid.');
        }

        @chmod($root, 0700);
        $this->statePath = $root . '/checkpoints.json';
        $this->lockPath = $root . '/checkpoints.lock';
    }

    public function read(string $taskKey): ?string
    {
        $this->validateKey($taskKey, 'task');

        /** @var ?string $result */
        $result = $this->withLock(function () use ($taskKey): ?string {
            $state = $this->load();
            return $state['checkpoints'][$taskKey] ?? null;
        });
        return $result;
    }

    public function initialize(string $taskKey, string $cursor): void
    {
        $this->validateKey($taskKey, 'task');
        $this->validateCursor($cursor);

        $this->withLock(function () use ($taskKey, $cursor): void {
            $state = $this->load();
            if (isset($state['checkpoints'][$taskKey])) {
                throw new InvalidArgumentException('Occurrence checkpoint already exists.');
            }
            $this->replaceState($state, $taskKey, $cursor);
        });
    }

    public function advance(string $taskKey, string $expectedCursor, string $nextCursor): void
    {
        $this->update($taskKey, $expectedCursor, $nextCursor);
    }

    public function skip(string $taskKey, string $expectedCursor, string $nextCursor, OccurrenceSkipReason $reason): void
    {
        // The enum is validated before entering the CAS update. Capacity is
        // deliberately not a skip reason and therefore cannot consume a cursor.
        $this->update($taskKey, $expectedCursor, $nextCursor);
    }

    private function update(string $taskKey, string $expectedCursor, string $nextCursor): void
    {
        $this->validateKey($taskKey, 'task');
        $this->validateCursor($expectedCursor);
        $this->validateCursor($nextCursor);
        if ($this->compareCursors($expectedCursor, $nextCursor) >= 0) {
            throw new InvalidArgumentException('Occurrence checkpoint cursor must move forward.');
        }

        $this->withLock(function () use ($taskKey, $expectedCursor, $nextCursor): void {
            $state = $this->load();
            if (($state['checkpoints'][$taskKey] ?? null) !== $expectedCursor) {
                throw new InvalidArgumentException('Occurrence checkpoint cursor is stale.');
            }
            $this->replaceState($state, $taskKey, $nextCursor);
        });
    }

    /** @param array{schema: string, checkpoints: array<string, string>} $state */
    private function replaceState(array $state, string $taskKey, string $cursor): void
    {
        /** @var array<string, string> $checkpoints */
        $checkpoints = $state['checkpoints'];
        $checkpoints[$taskKey] = $cursor;
        $state['checkpoints'] = $checkpoints;
        $state = $this->validateState($state);
        $encoded = $this->encode($state);
        if (strlen($encoded) > self::MAX_STATE_BYTES) {
            throw new InvalidArgumentException('Occurrence checkpoint state is too large.');
        }
        $this->replace($encoded);
    }

    private function validateCursor(string $cursor): void
    {
        if ($cursor === '' || strlen($cursor) > 2048 || preg_match('/^[A-Za-z0-9._~:-]+$/D', $cursor) !== 1) {
            throw new InvalidArgumentException('Occurrence checkpoint cursor is invalid.');
        }
    }

    /** Return a strict ordering: negative, zero, or positive. */
    private function compareCursors(string $expectedCursor, string $nextCursor): int
    {
        if (preg_match('/^t([0-9]{10})$/D', $expectedCursor, $expectedParts) === 1
            && preg_match('/^t([0-9]{10})$/D', $nextCursor, $nextParts) === 1
        ) {
            return $this->compareDecimalStrings($expectedParts[1], $nextParts[1]);
        }

        if (preg_match('/^(.+?)([0-9]+)$/D', $expectedCursor, $expectedParts) === 1
            && preg_match('/^(.+?)([0-9]+)$/D', $nextCursor, $nextParts) === 1
            && preg_match('/[^0-9]/', $expectedParts[1]) === 1
            && $expectedParts[1] === $nextParts[1]
        ) {
            return $this->compareDecimalStrings($expectedParts[2], $nextParts[2]);
        }

        return strcmp($expectedCursor, $nextCursor);
    }

    /** Compare bounded decimal strings without integer overflow. */
    private function compareDecimalStrings(string $left, string $right): int
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');
        $left = $left === '' ? '0' : $left;
        $right = $right === '' ? '0' : $right;
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    /** @return array{schema: string, checkpoints: array<string, string>} */
    private function load(): array
    {
        if (!is_file($this->statePath)) {
            return ['schema' => self::SCHEMA, 'checkpoints' => []];
        }
        $size = filesize($this->statePath);
        if ($size === false || $size > self::MAX_STATE_BYTES) {
            throw new InvalidArgumentException('Occurrence checkpoint state is too large.');
        }
        $contents = @file_get_contents($this->statePath);
        if ($contents === false || $contents === '') {
            throw new InvalidArgumentException('Occurrence checkpoint state cannot be read.');
        }
        if ($this->hasDuplicateObjectMembers($contents)) {
            throw new InvalidArgumentException('Occurrence checkpoint state has duplicate members.');
        }
        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Occurrence checkpoint state is malformed.', 0, $exception);
        }
        return $this->validateState($decoded);
    }

    /**
     * @param mixed $state
     * @return array{schema: string, checkpoints: array<string, string>}
     */
    private function validateState(mixed $state): array
    {
        if (!is_array($state) || count($state) !== 2 || !array_key_exists('schema', $state) || !array_key_exists('checkpoints', $state) || $state['schema'] !== self::SCHEMA || !is_array($state['checkpoints'])) {
            throw new InvalidArgumentException('Occurrence checkpoint state has an unexpected shape.');
        }
        $entries = 0;
        /** @var array<string, string> $checkpoints */
        $checkpoints = [];
        foreach ($state['checkpoints'] as $taskKey => $cursor) {
            $entries++;
            if (!is_string($taskKey)) {
                throw new InvalidArgumentException('Occurrence checkpoint task key is invalid.');
            }
            $this->validateKey($taskKey, 'task');
            if (!is_string($cursor) || $cursor === '' || strlen($cursor) > 2048 || preg_match('/^[A-Za-z0-9._~:-]+$/D', $cursor) !== 1) {
                throw new InvalidArgumentException('Occurrence checkpoint cursor is invalid.');
            }
            $checkpoints[$taskKey] = $cursor;
        }
        if ($entries > self::MAX_ENTRIES) {
            throw new InvalidArgumentException('Occurrence checkpoint state is too large.');
        }
        return ['schema' => self::SCHEMA, 'checkpoints' => $checkpoints];
    }

    private function hasDuplicateObjectMembers(string $json): bool
    {
        /** @var list<array<string, true>> $objects */
        $objects = [];
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            if ($json[$i] === '"') {
                $start = $i++;
                for (; $i < $length; $i++) {
                    if ($json[$i] === '\\') {
                        $i++;
                    } elseif ($json[$i] === '"') {
                        break;
                    }
                }
                $key = json_decode(substr($json, $start, $i - $start + 1), true);
                if (!is_string($key)) {
                    continue;
                }
                $j = $i + 1;
                while ($j < $length && in_array($json[$j], [' ', "\t", "\r", "\n"], true)) {
                    $j++;
                }
                if ($j < $length && $json[$j] === ':' && $objects !== []) {
                    $object = count($objects) - 1;
                    if (isset($objects[$object][$key])) {
                        return true;
                    }
                    $objects[$object][$key] = true;
                }
            } elseif ($json[$i] === '{') {
                $objects[] = [];
            } elseif ($json[$i] === '}' && $objects !== []) {
                array_pop($objects);
            }
        }
        return false;
    }

    private function encode(array $state): string
    {
        try {
            return json_encode($state, JSON_THROW_ON_ERROR) . "\n";
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Occurrence checkpoint state cannot be encoded.', 0, $exception);
        }
    }

    private function replace(string $contents): void
    {
        $temporary = $this->statePath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        @chmod($temporary, 0600);
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new InvalidArgumentException('Occurrence checkpoint temporary file cannot be created.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new InvalidArgumentException('Occurrence checkpoint cannot be written.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->statePath)) {
            @unlink($temporary);
            throw new InvalidArgumentException('Occurrence checkpoint cannot replace its state file.');
        }
        @chmod($this->statePath, 0600);
    }

    /** @template T @param callable(): T $operation @return T */
    private function withLock(callable $operation): mixed
    {
        $handle = @fopen($this->lockPath, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new InvalidArgumentException('Occurrence checkpoint lock cannot be acquired.');
        }
        @chmod($this->lockPath, 0600);
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function validateKey(string $key, string $kind): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Occurrence checkpoint %s key is invalid.', $kind));
        }
    }
}
