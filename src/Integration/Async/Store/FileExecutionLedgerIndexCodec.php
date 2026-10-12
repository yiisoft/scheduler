<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Store;

use InvalidArgumentException;
use Throwable;

use function array_diff;
use function array_keys;
use function count;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function strlen;
use function range;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class FileExecutionLedgerIndexCodec
{
    public const MAX_BYTES = 1024 * 1024;

    /** @param list<array{taskKey:string,occurrenceId:string}> $entries */
    public function encodeActive(array $entries): string
    {
        return $this->encode($entries, ['taskKey', 'occurrenceId']);
    }

    /** @param list<array{taskKey:string}> $entries */
    public function encodeHistory(array $entries): string
    {
        return $this->encode($entries, ['taskKey']);
    }

    /** @return list<array{taskKey:string,occurrenceId:string}> */
    public function decodeActive(string $json): array
    {
        /** @var list<array{taskKey:string,occurrenceId:string}> */
        return $this->decode($json, ['taskKey', 'occurrenceId']);
    }

    /** @param list<array{taskKey:string}> $entries */
    public function encodeManifest(array $entries): string
    {
        return $this->encode($entries, ['taskKey']);
    }

    /** @return list<array{taskKey:string}> */
    public function decodeManifest(string $json): array
    {
        /** @var list<array{taskKey:string}> */
        return $this->decode($json, ['taskKey']);
    }

    /** @return list<array{taskKey:string}> */
    public function decodeHistory(string $json): array
    {
        /** @var list<array{taskKey:string}> */
        return $this->decode($json, ['taskKey']);
    }

    /** @param list<mixed> $entries @param list<string> $keys */
    private function encode(array $entries, array $keys): string
    {
        $ids = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), $keys) !== [] || array_diff($keys, array_keys($entry)) !== [] || count($entry) !== count($keys)) {
                throw new InvalidArgumentException('Ledger index entry is invalid.');
            }
            foreach ($entry as $value) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException('Ledger index key is invalid.');
                }
            }
            /** @var array<string, string> $entry */
            $id = implode("\0", array_map(static fn(string $key): string => $entry[$key], $keys));
            if (isset($ids[$id])) {
                throw new InvalidArgumentException('Ledger index contains duplicate entries.');
            }
            $ids[$id] = true;
        }
        try {
            $json = json_encode(['version' => 2, 'entries' => $entries], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Ledger index cannot be encoded.', 0, $e);
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Ledger index is oversized.');
        }
        return $json;
    }

    /** @param list<string> $keys @return list<array<string, string>> */
    private function decode(string $json, array $keys): array
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Ledger index is malformed.');
        }
        try {
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Ledger index is malformed.', 0, $e);
        }
        if (!is_array($value) || array_diff(array_keys($value), ['version', 'entries']) !== [] || count($value) !== 2 || $value['version'] !== 2 || !is_array($value['entries'])) {
            throw new InvalidArgumentException('Ledger index shape is invalid.');
        }
        if ($value['entries'] !== [] && array_keys($value['entries']) !== range(0, count($value['entries']) - 1)) {
            throw new InvalidArgumentException('Ledger index entries must be a list.');
        }
        $out = [];
        $ids = [];
        foreach ($value['entries'] as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), $keys) !== [] || array_diff($keys, array_keys($entry)) !== [] || count($entry) !== count($keys)) {
                throw new InvalidArgumentException('Ledger index entry shape is invalid.');
            }
            /** @var array<string, mixed> $entry */
            foreach ($keys as $key) {
                if (!is_string($entry[$key]) || $entry[$key] === '') {
                    throw new InvalidArgumentException('Ledger index key is invalid.');
                }
            }
            /** @var array<string, string> $entry */
            $id = implode("\0", array_map(static fn(string $key): string => $entry[$key], $keys));
            if (isset($ids[$id])) {
                throw new InvalidArgumentException('Ledger index contains duplicate entries.');
            }
            $ids[$id] = true;
            $out[] = $entry;
        }
        return $out;
    }
}
