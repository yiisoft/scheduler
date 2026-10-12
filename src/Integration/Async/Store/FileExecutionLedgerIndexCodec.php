<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async\Store;

use InvalidArgumentException;
use Throwable;

use function array_diff;
use function count;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function strlen;
use function range;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/** Strict, versioned representation of lookup indexes and the task manifest. */
final class FileExecutionLedgerIndexCodec
{
    public const MAX_BYTES = 1024 * 1024;

    /** @param list<array{scheduleKey:string,taskKey:string,occurrenceId:string}> $entries */
    public function encodeActive(array $entries): string
    {
        return $this->encode($entries, ['scheduleKey', 'taskKey', 'occurrenceId']);
    }

    /** @param list<array{scheduleKey:string,taskKey:string}> $entries */
    public function encodeHistory(array $entries): string
    {
        return $this->encode($entries, ['scheduleKey', 'taskKey']);
    }

    /** @return list<array{scheduleKey:string,taskKey:string,occurrenceId:string}> */
    public function decodeActive(string $json): array
    {
        $result = $this->decode($json, ['scheduleKey', 'taskKey', 'occurrenceId']);
        /** @var list<array{scheduleKey:string,taskKey:string,occurrenceId:string}> $result */
        return $result;
    }

    /** @param list<array{scheduleKey:string,taskKey:string}> $entries */
    public function encodeManifest(array $entries): string
    {
        return $this->encode($entries, ['scheduleKey', 'taskKey']);
    }

    /** @return list<array{scheduleKey:string,taskKey:string}> */
    public function decodeManifest(string $json): array
    {
        $result = $this->decode($json, ['scheduleKey', 'taskKey']);
        /** @var list<array{scheduleKey:string,taskKey:string}> $result */
        return $result;
    }

    /** @return list<array{scheduleKey:string,taskKey:string}> */
    public function decodeHistory(string $json): array
    {
        $result = $this->decode($json, ['scheduleKey', 'taskKey']);
        /** @var list<array{scheduleKey:string,taskKey:string}> $result */
        return $result;
    }

    /**
     * @param list<mixed> $entries
     * @param list<string> $keys
     */
    private function encode(array $entries, array $keys): string
    {
        $identities = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), $keys) !== [] || array_diff($keys, array_keys($entry)) !== [] || count($entry) !== count($keys)) {
                throw new InvalidArgumentException('Ledger index entry is invalid.');
            }
            foreach ($keys as $key) {
                if (!is_string($entry[$key])) {
                    throw new InvalidArgumentException('Ledger index key is invalid.');
                }
            }
            /** @var array<string,string> $entry */
            $identity = implode("\0", array_map(static fn(string $key): string => $entry[$key], $keys));
            if (isset($identities[$identity])) {
                throw new InvalidArgumentException('Ledger index contains duplicate entries.');
            }
            $identities[$identity] = true;
        }
        try {
            $json = json_encode(['version' => 1, 'entries' => $entries], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Ledger index cannot be encoded.', 0, $e);
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Ledger index is oversized.');
        }
        return $json;
    }

    /**
     * @param list<string> $keys
     * @return list<array<string,string>>
     */
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
        if (!is_array($value) || array_diff(array_keys($value), ['version', 'entries']) !== [] || count($value) !== 2) {
            throw new InvalidArgumentException('Ledger index shape is invalid.');
        }
        /** @var array{version:mixed,entries:mixed} $value */
        if ($value['version'] !== 1 || !is_array($value['entries'])) {
            throw new InvalidArgumentException('Ledger index shape is invalid.');
        }
        $result = [];
        if ($value['entries'] !== [] && array_keys($value['entries']) !== range(0, count($value['entries']) - 1)) {
            throw new InvalidArgumentException('Ledger index entries must be a list.');
        }
        $identities = [];
        foreach ($value['entries'] as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), $keys) !== [] || array_diff($keys, array_keys($entry)) !== [] || count($entry) !== count($keys)) {
                throw new InvalidArgumentException('Ledger index entry is invalid.');
            }
            foreach ($keys as $key) {
                if (!is_string($entry[$key])) {
                    throw new InvalidArgumentException('Ledger index key is invalid.');
                }
            }
            /** @var array<string,string> $entry */
            $identity = implode("\0", array_map(static fn(string $key): string => $entry[$key], $keys));
            if (isset($identities[$identity])) {
                throw new InvalidArgumentException('Ledger index contains duplicate entries.');
            }
            $identities[$identity] = true;
            $result[] = $entry;
        }
        return $result;
    }
}
