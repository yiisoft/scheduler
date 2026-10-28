<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Store;

use InvalidArgumentException;
use Throwable;

use function array_diff;
use function array_keys;
use function array_pop;
use function count;
use function implode;
use function is_array;
use function is_string;
use function in_array;
use function json_decode;
use function json_encode;
use function strlen;
use function substr;
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

    /**
     * Decode only the initial recovery window. Once the requested prefix has
     * been consumed, the byte position after its comma is returned without
     * looking at the following entry. Full validation remains the responsibility
     * of decodeActiveWindow(), used for durable validation.
     *
     * @return array{entries:list<array{taskKey:string,occurrenceId:string}>,next:int,complete:bool}
     */
    public function decodeActiveWindowInitial(string $json, int $size): array
    {
        if ($size < 1 || $json === '' || strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Ledger index window is invalid.');
        }
        $length = strlen($json);
        $i = $this->skipWhitespace($json, 0);
        if ($i >= $length || $json[$i] !== '{') {
            throw new InvalidArgumentException('Ledger index shape is invalid.');
        }
        $i++;
        $schema = false;
        $entriesSeen = false;
        /** @var list<array{taskKey:string,occurrenceId:string}> $window */
        $window = [];
        /** @var array{taskKey:string,occurrenceId:string}|null $previous */
        $previous = null;
        while (true) {
            $i = $this->skipWhitespace($json, $i);
            if ($i >= $length || $json[$i] === '}') {
                throw new InvalidArgumentException('Ledger index shape is invalid.');
            }
            $keyEnd = $this->jsonValueEnd($json, $i);
            try {
                $key = json_decode(substr($json, $i, $keyEnd - $i), true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('Ledger index is malformed.', 0, $e);
            }
            if (!is_string($key)) {
                throw new InvalidArgumentException('Ledger index shape is invalid.');
            }
            $i = $this->skipWhitespace($json, $keyEnd);
            if ($i >= $length || $json[$i] !== ':') {
                throw new InvalidArgumentException('Ledger index is malformed.');
            }
            $valueStart = $this->skipWhitespace($json, $i + 1);
            if ($key === 'schema') {
                $valueEnd = $this->jsonValueEnd($json, $valueStart);
                $value = json_decode(substr($json, $valueStart, $valueEnd - $valueStart), true);
                if ($schema || $value !== 'dev') {
                    throw new InvalidArgumentException('Ledger index shape is invalid.');
                }
                $schema = true;
                $i = $valueEnd;
            } elseif ($key === 'entries') {
                if (!$schema || $entriesSeen || $json[$valueStart] !== '[') {
                    throw new InvalidArgumentException('Ledger index shape is invalid.');
                }
                $entriesSeen = true;
                $i = $valueStart + 1;
                while (true) {
                    $i = $this->skipWhitespace($json, $i);
                    if ($i >= $length) {
                        throw new InvalidArgumentException('Ledger index is malformed.');
                    }
                    if ($json[$i] === ']') {
                        $i++;
                        break;
                    }
                    $entryStart = $i;
                    $entryEnd = $this->jsonValueEnd($json, $entryStart);
                    if ($json[$entryStart] !== '{') {
                        throw new InvalidArgumentException('Ledger index entry shape is invalid.');
                    }
                    try {
                        $entry = json_decode(substr($json, $entryStart, $entryEnd - $entryStart), true, 16, JSON_THROW_ON_ERROR);
                    } catch (Throwable $e) {
                        throw new InvalidArgumentException('Ledger index entry is malformed.', 0, $e);
                    }
                    $entry = $this->validateActiveEntry($entry);
                    if ($previous !== null && $this->compareActiveEntries($previous, $entry) >= 0) {
                        throw new InvalidArgumentException('Ledger active index is not sorted.');
                    }
                    $previous = $entry;
                    $window[] = $entry;
                    $i = $this->skipWhitespace($json, $entryEnd);
                    if ($i >= $length) {
                        throw new InvalidArgumentException('Ledger index is malformed.');
                    }
                    if ($json[$i] === ',') {
                        $next = $this->skipWhitespace($json, $i + 1);
                        if ($next >= $length || $json[$next] === ']') {
                            throw new InvalidArgumentException('Ledger index is malformed.');
                        }
                        if (count($window) === $size) {
                            return ['entries' => $window, 'next' => $next, 'complete' => false];
                        }
                        $i = $next;
                        continue;
                    }
                    if ($json[$i] !== ']') {
                        throw new InvalidArgumentException('Ledger index is malformed.');
                    }
                    $i++;
                    break;
                }
            } else {
                throw new InvalidArgumentException('Ledger index shape is invalid.');
            }
            $i = $this->skipWhitespace($json, $i);
            if ($i < $length && $json[$i] === ',') {
                $i = $this->skipWhitespace($json, $i + 1);
                if ($i >= $length || $json[$i] === '}') {
                    throw new InvalidArgumentException('Ledger index is malformed.');
                }
                continue;
            }
            if ($i >= $length || $json[$i] !== '}') {
                throw new InvalidArgumentException('Ledger index is malformed.');
            }
            $i = $this->skipWhitespace($json, $i + 1);
            if ($i !== $length || !$entriesSeen) {
                throw new InvalidArgumentException('Ledger index shape is invalid.');
            }
            return ['entries' => $window, 'next' => $length, 'complete' => true];
        }
    }

    /**
     * Stream the active-index array, retaining only the requested entries. The
     * position is a byte offset at the beginning of an entry (zero means the
     * first entry); this makes continuation independent of entry count.
     *
     * The whole byte stream is still checked, including duplicate IDs. The ID
     * set is bounded by MAX_BYTES and is validation state, not a materialized
     * entries array. No json_decode is performed on the entries array itself.
     *
     * @return array{entries:list<array{taskKey:string,occurrenceId:string}>,next:int,complete:bool}
     */
    public function decodeActiveWindow(string $json, int $position, int $size): array
    {
        if ($position < 0 || $size < 1 || $json === '' || strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Ledger index window is invalid.');
        }
        if ($this->hasDuplicateObjectMembers($json)) {
            throw new InvalidArgumentException('Ledger index contains duplicate members.');
        }
        $length = strlen($json);
        $i = $this->skipWhitespace($json, 0);
        if ($i >= $length || $json[$i] !== '{') {
            throw new InvalidArgumentException('Ledger index shape is invalid.');
        }
        $i++;
        $schema = false;
        $entriesSeen = false;
        $found = $position === 0 || $position === $length;
        $window = [];
        $ids = [];
        $nextPosition = $length;
        $entryCount = 0;
        $previous = null;
        while (true) {
            $i = $this->skipWhitespace($json, $i);
            if ($i >= $length) {
                throw new InvalidArgumentException('Ledger index is malformed.');
            }
            if ($json[$i] === '}') {
                $i++;
                break;
            }
            $keyStart = $i;
            $keyEnd = $this->jsonValueEnd($json, $i);
            $key = json_decode(substr($json, $keyStart, $keyEnd - $keyStart), true);
            if (!is_string($key)) {
                throw new InvalidArgumentException('Ledger index shape is invalid.');
            }
            $i = $this->skipWhitespace($json, $keyEnd);
            if ($i >= $length || $json[$i] !== ':') {
                throw new InvalidArgumentException('Ledger index is malformed.');
            }
            $valueStart = $this->skipWhitespace($json, $i + 1);
            if ($key === 'schema') {
                $valueEnd = $this->jsonValueEnd($json, $valueStart);
                $value = json_decode(substr($json, $valueStart, $valueEnd - $valueStart), true);
                if ($schema || $value !== 'dev') {
                    throw new InvalidArgumentException('Ledger index shape is invalid.');
                }
                $schema = true;
                $i = $valueEnd;
            } elseif ($key === 'entries') {
                if (!$schema || $entriesSeen || $json[$valueStart] !== '[') {
                    throw new InvalidArgumentException('Ledger index shape is invalid.');
                }
                $entriesSeen = true;
                $i = $valueStart + 1;
                $entryCount = 0;
                while (true) {
                    $i = $this->skipWhitespace($json, $i);
                    if ($i >= $length) {
                        throw new InvalidArgumentException('Ledger index is malformed.');
                    }
                    if ($json[$i] === ']') {
                        $i++;
                        break;
                    }
                    $entryPosition = $i;
                    $entryEnd = $this->jsonValueEnd($json, $entryPosition);
                    if ($json[$entryPosition] !== '{') {
                        throw new InvalidArgumentException('Ledger index entry shape is invalid.');
                    }
                    if ($position > 0 && $entryPosition === $position) {
                        $found = true;
                    }
                    try {
                        $entry = json_decode(substr($json, $entryPosition, $entryEnd - $entryPosition), true, 16, JSON_THROW_ON_ERROR);
                    } catch (Throwable $e) {
                        throw new InvalidArgumentException('Ledger index entry is malformed.', 0, $e);
                    }
                    $entry = $this->validateActiveEntry($entry);
                    if ($previous !== null && $this->compareActiveEntries($previous, $entry) >= 0) {
                        throw new InvalidArgumentException('Ledger active index is not sorted.');
                    }
                    $previous = $entry;
                    $id = $entry['taskKey'] . "\0" . $entry['occurrenceId'];
                    if (isset($ids[$id])) {
                        throw new InvalidArgumentException('Ledger index contains duplicate entries.');
                    }
                    $ids[$id] = true;
                    if (($position === 0 && $entryCount < $size) || ($position > 0 && $entryPosition >= $position && count($window) < $size)) {
                        $window[] = $entry;
                    }
                    $entryCount++;
                    $i = $this->skipWhitespace($json, $entryEnd);
                    if (count($window) === $size && $nextPosition === $length) {
                        $nextPosition = $i < $length && $json[$i] === ','
                            ? $this->skipWhitespace($json, $i + 1)
                            : $length;
                    }
                    if ($i < $length && $json[$i] === ',') {
                        $i = $this->skipWhitespace($json, $i + 1);
                        if ($i >= $length || $json[$i] === ']') {
                            throw new InvalidArgumentException('Ledger index is malformed.');
                        }
                        continue;
                    }
                    if ($i >= $length || $json[$i] !== ']') {
                        throw new InvalidArgumentException('Ledger index is malformed.');
                    }
                    $i++;
                    break;
                }
            } else {
                throw new InvalidArgumentException('Ledger index shape is invalid.');
            }
            $i = $this->skipWhitespace($json, $i);
            if ($i < $length && $json[$i] === ',') {
                $i = $this->skipWhitespace($json, $i + 1);
                if ($i >= $length || $json[$i] === '}') {
                    throw new InvalidArgumentException('Ledger index is malformed.');
                }
                continue;
            }
            if ($i >= $length || $json[$i] !== '}') {
                throw new InvalidArgumentException('Ledger index is malformed.');
            }
            $i++;
            break;
        }
        if (!$schema || !$entriesSeen || $this->skipWhitespace($json, $i) !== $length) {
            throw new InvalidArgumentException('Ledger index shape is invalid.');
        }
        if (!$found || ($position !== 0 && $position !== $length && $window === [] && $entryCount > 0)) {
            throw new InvalidArgumentException('Ledger index cursor is stale.');
        }
        return ['entries' => $window, 'next' => $nextPosition, 'complete' => $nextPosition === $length];
    }

    /**
     * @param resource $handle
     * @return array{entries:list<array{taskKey:string,occurrenceId:string}>,next:int,start:int,complete:bool}
     */
    public function decodeActiveWindowInitialStream($handle): array
    {
        if (fseek($handle, 0) !== 0) {
            throw new InvalidArgumentException('Ledger index window is invalid.');
        }
        $prefix = '';
        $offset = 0;
        while (true) {
            $byte = fread($handle, 1);
            if ($byte === false || $byte === '') {
                throw new InvalidArgumentException('Ledger index is malformed.');
            }
            $offset++;
            $prefix .= $byte;
            if (strlen($prefix) > self::MAX_BYTES) {
                throw new InvalidArgumentException('Ledger index is oversized.');
            }
            if (substr($prefix, -1) === '[' && strpos($prefix, '"entries"') !== false) {
                try {
                    $header = json_decode($prefix . ']}', true, 16, JSON_THROW_ON_ERROR);
                } catch (Throwable $e) {
                    throw new InvalidArgumentException('Ledger index shape is invalid.', 0, $e);
                }
                if (!is_array($header) || count($header) !== 2 || array_keys($header) !== ['schema', 'entries'] || $header['schema'] !== 'dev' || !is_array($header['entries']) || $header['entries'] !== []) {
                    throw new InvalidArgumentException('Ledger index shape is invalid.');
                }
                do {
                    $byte = fread($handle, 1);
                    $offset++;
                } while ($byte !== false && $byte !== '' && $this->isWhitespace($byte));
                if ($byte === ']') {
                    do {
                        $byte = fread($handle, 1);
                        $offset++;
                    } while ($byte !== false && $byte !== '' && $this->isWhitespace($byte));
                    if ($byte !== '}') {
                        throw new InvalidArgumentException('Ledger index is malformed.');
                    }
                    return ['entries' => [], 'next' => $offset, 'start' => $offset, 'complete' => true];
                }
                if ($byte !== '{') {
                    throw new InvalidArgumentException('Ledger index is malformed.');
                }
                fseek($handle, $offset - 1);
                return $this->decodeActiveWindowStream($handle, $offset - 1, 1);
            }
        }
    }

    /**
     * Resynchronize a continuation after an atomic publication. The logical
     * predecessor, rather than the old byte offset, is authoritative. Each
     * decoded window is bounded; the prefix is traversed only once when a
     * publication changed.
     *
     * @param resource $handle
     * @param array{taskKey:string,occurrenceId:string}|null $previous
     * @return array{entries:list<array{taskKey:string,occurrenceId:string}>,next:int,start:int,complete:bool}
     */
    public function decodeActiveWindowAfterKeyStream($handle, ?array $previous = null): array
    {
        $window = $this->decodeActiveWindowInitialStream($handle);
        if ($previous === null) {
            return $window;
        }
        while ($window['entries'] !== []) {
            $entry = $window['entries'][0];
            if ($this->compareActiveEntries($previous, $entry) < 0) {
                return $window;
            }
            if ($window['complete']) {
                return ['entries' => [], 'next' => $window['next'], 'start' => $window['next'], 'complete' => true];
            }
            $window = $this->decodeActiveWindowStream($handle, $window['next'], 1, $entry);
        }
        return $window;
    }

    /**
     * Read one bounded entry window from an opened index stream. The stream
     * position is the beginning of an entry (zero requests the initial
     * entry); no bytes after the requested entry are inspected.
     *
     * @param resource $handle
     * @param array{taskKey:string,occurrenceId:string}|null $previous
     * @return array{entries:list<array{taskKey:string,occurrenceId:string}>,next:int,start:int,complete:bool}
     */
    public function decodeActiveWindowStream($handle, int $position, int $size, ?array $previous = null): array
    {
        if ($size !== 1 || $position < 0) {
            throw new InvalidArgumentException('Ledger index window is invalid.');
        }
        if (fseek($handle, $position) !== 0) {
            throw new InvalidArgumentException('Ledger index cursor is stale.');
        }
        $offset = $position;
        $read = static function () use ($handle, &$offset): string {
            $byte = fread($handle, 1);
            if ($byte === false || $byte === '') {
                throw new InvalidArgumentException('Ledger index is malformed.');
            }
            $offset++;
            return $byte;
        };
        do {
            $byte = $read();
        } while ($this->isWhitespace($byte));
        if ($byte !== '{') {
            throw new InvalidArgumentException('Ledger index cursor is stale.');
        }
        $raw = '{';
        $depth = 1;
        $quoted = false;
        $escaped = false;
        while ($depth > 0) {
            $byte = $read();
            $raw .= $byte;
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($byte === '\\') {
                    $escaped = true;
                } elseif ($byte === '"') {
                    $quoted = false;
                }
            } elseif ($byte === '"') {
                $quoted = true;
            } elseif ($byte === '{') {
                $depth++;
            } elseif ($byte === '}') {
                $depth--;
            }
            if (strlen($raw) > self::MAX_BYTES) {
                throw new InvalidArgumentException('Ledger index is oversized.');
            }
        }
        if ($this->hasDuplicateObjectMembers($raw)) {
            throw new InvalidArgumentException('Ledger index contains duplicate members.');
        }
        try {
            $entry = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Ledger index entry is malformed.', 0, $e);
        }
        $entry = $this->validateActiveEntry($entry);
        if ($previous !== null && $this->compareActiveEntries($previous, $entry) >= 0) {
            throw new InvalidArgumentException('Ledger active index is not sorted.');
        }
        do {
            $byte = $read();
        } while ($this->isWhitespace($byte));
        if ($byte === ',') {
            do {
                $byte = $read();
            } while ($this->isWhitespace($byte));
            if ($byte !== '{') {
                throw new InvalidArgumentException('Ledger index is malformed.');
            }
            fseek($handle, $offset - 1);
            return ['entries' => [$entry], 'next' => $offset - 1, 'start' => $position, 'complete' => false];
        }
        if ($byte !== ']') {
            throw new InvalidArgumentException('Ledger index is malformed.');
        }
        do {
            $byte = $read();
        } while ($this->isWhitespace($byte));
        if ($byte !== '}') {
            throw new InvalidArgumentException('Ledger index is malformed.');
        }
        return ['entries' => [$entry], 'next' => $offset, 'start' => $position, 'complete' => true];
    }

    /**
     * Decode only a continuation window beginning at an entry boundary.
     *
     * Unlike decodeActive(), this deliberately does not validate bytes before
     * the boundary or after the requested window.  The preceding key is read
     * from the boundary (or may be supplied by a stream caller) so ordering
     * remains strict across pages without retaining or rescanning a prefix.
     *
     * @param array{taskKey:string,occurrenceId:string}|null $previous
     * @return array{entries:list<array{taskKey:string,occurrenceId:string}>,next:int,complete:bool}
     */
    public function decodeActiveWindowFast(string $json, int $position, int $size, ?array $previous = null): array
    {
        $length = strlen($json);
        if ($position <= 0 || $size < 1 || $json === '' || $length > self::MAX_BYTES || $position > $length) {
            throw new InvalidArgumentException('Ledger index window is invalid.');
        }
        if ($position === $length) {
            return ['entries' => [], 'next' => $length, 'complete' => true];
        }

        $i = $this->skipWhitespace($json, $position);
        if ($i !== $position || $json[$i] !== '{') {
            throw new InvalidArgumentException('Ledger index cursor is stale.');
        }
        if ($previous === null) {
            $previous = $this->precedingActiveEntry($json, $position);
        } else {
            $previous = $this->validateActiveEntry($previous);
        }

        /** @var list<array{taskKey:string,occurrenceId:string}> $entries */
        $entries = [];
        while (count($entries) < $size) {
            if ($i >= $length || $json[$i] !== '{') {
                throw new InvalidArgumentException('Ledger index entry shape is invalid.');
            }
            $end = $this->jsonValueEnd($json, $i);
            $raw = substr($json, $i, $end - $i);
            if ($this->hasDuplicateObjectMembers($raw)) {
                throw new InvalidArgumentException('Ledger index contains duplicate members.');
            }
            try {
                $entry = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('Ledger index entry is malformed.', 0, $e);
            }
            $entry = $this->validateActiveEntry($entry);
            if ($this->compareActiveEntries($previous, $entry) >= 0) {
                throw new InvalidArgumentException('Ledger active index is not sorted.');
            }
            $entries[] = $entry;
            $previous = $entry;
            $i = $this->skipWhitespace($json, $end);
            if ($i >= $length) {
                throw new InvalidArgumentException('Ledger index is malformed.');
            }
            if ($json[$i] === ',') {
                $next = $this->skipWhitespace($json, $i + 1);
                if ($next >= $length || $json[$next] === ']') {
                    throw new InvalidArgumentException('Ledger index is malformed.');
                }
                if (count($entries) === $size) {
                    return ['entries' => $entries, 'next' => $next, 'complete' => false];
                }
                $i = $next;
                continue;
            }
            if ($json[$i] !== ']') {
                throw new InvalidArgumentException('Ledger index is malformed.');
            }
            $i = $this->skipWhitespace($json, $i + 1);
            if ($i >= $length || $json[$i] !== '}') {
                throw new InvalidArgumentException('Ledger index is malformed.');
            }
            $i = $this->skipWhitespace($json, $i + 1);
            if ($i !== $length) {
                throw new InvalidArgumentException('Ledger index is malformed.');
            }
            return ['entries' => $entries, 'next' => $length, 'complete' => true];
        }

        return ['entries' => $entries, 'next' => $i, 'complete' => false];
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

    private function isWhitespace(string $byte): bool
    {
        return $byte === ' ' || $byte === "\t" || $byte === "\r" || $byte === "\n";
    }

    private function skipWhitespace(string $json, int $position): int
    {
        $length = strlen($json);
        while ($position < $length && in_array($json[$position], [' ', "\t", "\r", "\n"], true)) {
            $position++;
        }
        return $position;
    }

    private function jsonValueEnd(string $json, int $start): int
    {
        $length = strlen($json);
        if ($start >= $length) {
            throw new InvalidArgumentException('Ledger index is malformed.');
        }
        $open = ['{' => '}', '[' => ']'];
        if ($json[$start] === '"') {
            for ($i = $start + 1; $i < $length; $i++) {
                if ($json[$i] === '\\') {
                    $i++;
                } elseif ($json[$i] === '"') {
                    return $i + 1;
                }
            }
            throw new InvalidArgumentException('Ledger index is malformed.');
        }
        $stack = [];
        $inString = false;
        for ($i = $start; $i < $length; $i++) {
            $char = $json[$i];
            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif (isset($open[$char])) {
                $stack[] = $open[$char];
            } elseif ($stack !== [] && $char === $stack[count($stack) - 1]) {
                array_pop($stack);
                if ($stack === []) {
                    return $i + 1;
                }
            } elseif ($stack === []) {
                while ($i < $length && !in_array($json[$i], [',', ']', '}', ' ', "\t", "\r", "\n"], true)) {
                    $i++;
                }
                return $i;
            }
        }
        throw new InvalidArgumentException('Ledger index is malformed.');
    }

    /** @return array{taskKey:string,occurrenceId:string} */
    private function validateActiveEntry(mixed $entry): array
    {
        if (!is_array($entry) || array_diff(array_keys($entry), ['taskKey', 'occurrenceId']) !== [] || array_diff(['taskKey', 'occurrenceId'], array_keys($entry)) !== [] || count($entry) !== 2
            || !is_string($entry['taskKey']) || $entry['taskKey'] === '' || !is_string($entry['occurrenceId']) || $entry['occurrenceId'] === '') {
            throw new InvalidArgumentException('Ledger index entry shape is invalid.');
        }
        return ['taskKey' => $entry['taskKey'], 'occurrenceId' => $entry['occurrenceId']];
    }

    /** @param list<mixed> $entries @param list<string> $keys */
    private function encode(array $entries, array $keys): string
    {
        $ids = [];
        /** @var array{taskKey:string,occurrenceId:string}|null $previous */
        $previous = null;
        foreach ($entries as $entry) {
            if (!is_array($entry) || array_diff(array_keys($entry), $keys) !== [] || array_diff($keys, array_keys($entry)) !== [] || count($entry) !== count($keys)) {
                throw new InvalidArgumentException('Ledger index entry is invalid.');
            }
            foreach ($entry as $value) {
                if (!is_string($value) || ($keys === ['taskKey', 'occurrenceId'] && $value === '')) {
                    throw new InvalidArgumentException('Ledger index key is invalid.');
                }
            }
            /** @var array<string, string> $entry */
            $id = implode("\0", array_map(static fn(string $key): string => $entry[$key], $keys));
            if (isset($ids[$id])) {
                throw new InvalidArgumentException('Ledger index contains duplicate entries.');
            }
            $ids[$id] = true;
            if ($keys === ['taskKey', 'occurrenceId'] && $previous !== null) {
                /** @var array{taskKey:string,occurrenceId:string} $last */
                $last = $previous;
                if ($this->compareActiveEntries($last, $entry) >= 0) {
                    throw new InvalidArgumentException('Ledger active index is not sorted.');
                }
            }
            if ($keys === ['taskKey', 'occurrenceId']) {
                $previous = $entry;
            }
        }
        try {
            $json = json_encode(['schema' => 'dev', 'entries' => $entries], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Ledger index cannot be encoded.', 0, $e);
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Ledger index is oversized.');
        }
        return $json;
    }

    /** @return array{taskKey:string,occurrenceId:string} */
    private function precedingActiveEntry(string $json, int $position): array
    {
        for ($start = $position - 1; $start >= 0; $start--) {
            if ($json[$start] !== '{') {
                continue;
            }
            try {
                $end = $this->jsonValueEnd($json, $start);
            } catch (InvalidArgumentException) {
                continue;
            }
            $separator = $this->skipWhitespace($json, $end);
            if ($separator >= $position || $json[$separator] !== ',') {
                continue;
            }
            $separator = $this->skipWhitespace($json, $separator + 1);
            if ($separator !== $position) {
                continue;
            }
            $raw = substr($json, $start, $end - $start);
            if ($this->hasDuplicateObjectMembers($raw)) {
                throw new InvalidArgumentException('Ledger index contains duplicate members.');
            }
            try {
                $entry = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('Ledger index entry is malformed.', 0, $e);
            }
            return $this->validateActiveEntry($entry);
        }
        throw new InvalidArgumentException('Ledger index cursor is stale.');
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

    /** @param array<string, string> $left @param array<string, string> $right */
    private function compareActiveEntries(array $left, array $right): int
    {
        return $left['taskKey'] <=> $right['taskKey'] ?: $left['occurrenceId'] <=> $right['occurrenceId'];
    }

    /** @param list<string> $keys @return list<array<string, string>> */
    private function decode(string $json, array $keys): array
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Ledger index is malformed.');
        }
        if ($this->hasDuplicateObjectMembers($json)) {
            throw new InvalidArgumentException('Ledger index contains duplicate members.');
        }
        try {
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Ledger index is malformed.', 0, $e);
        }
        if (!is_array($value) || array_diff(array_keys($value), ['schema', 'entries']) !== [] || count($value) !== 2 || $value['schema'] !== 'dev' || !is_array($value['entries'])) {
            throw new InvalidArgumentException('Ledger index shape is invalid.');
        }
        if ($value['entries'] !== [] && array_keys($value['entries']) !== range(0, count($value['entries']) - 1)) {
            throw new InvalidArgumentException('Ledger index entries must be a list.');
        }
        $out = [];
        $ids = [];
        $previous = null;
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
            if ($keys === ['taskKey', 'occurrenceId']) {
                if ($previous !== null && $this->compareActiveEntries($previous, $entry) >= 0) {
                    throw new InvalidArgumentException('Ledger active index is not sorted.');
                }
                $previous = $entry;
            }
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
