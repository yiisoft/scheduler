<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler;

use InvalidArgumentException;
use ReflectionReference;

use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_null;
use function is_string;
use function strlen;
use function str_contains;

/** Validation for portable, serialization-safe scalar trees. */
final class ScalarMetadata
{
    private const MAX_DEPTH = 32;
    private const MAX_ITEMS = 10000;
    private const MAX_SERIALIZED_BYTES = 1048576;
    private const MAX_STRING_BYTES = 65536;

    public static function assertAggregateSupported(mixed ...$values): void
    {
        $items = 0;
        foreach ($values as $value) {
            self::assertSupported($value, 0, $items);
        }

        // Serialization is deliberately done only after the complete type walk.
        // At this point values are scalar trees and cannot invoke user code.
        $bytes = 0;
        foreach ($values as $value) {
            $bytes += strlen(serialize($value));
            if ($bytes > self::MAX_SERIALIZED_BYTES) {
                throw new InvalidArgumentException('Metadata aggregate exceeds the serialized size limit.');
            }
        }
    }

    public static function assertSupported(mixed $value, int $depth = 0, int &$items = 0): void
    {
        /** @var array<int, true> $activeReferences */
        $activeReferences = [];
        self::assertSupportedValue($value, $depth, $items, $activeReferences);
    }

    public static function assertNonEmpty(mixed $value, string $name): void
    {
        self::assertSupported($value);
        if ($value === null || $value === '' || $value === []) {
            throw new InvalidArgumentException($name . ' must not be empty.');
        }
    }

    /**
     * @param array<int, true> $activeReferences
     */
    private static function assertSupportedValue(mixed $value, int $depth, int &$items, array $activeReferences): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Metadata nesting is too deep.');
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && (strlen($key) > self::MAX_STRING_BYTES || str_contains($key, "\0"))) {
                    throw new InvalidArgumentException('Metadata string is oversized or contains NUL.');
                }
                if (++$items > self::MAX_ITEMS) {
                    throw new InvalidArgumentException('Metadata contains too many values.');
                }

                /** @var ReflectionReference|null $reference */
                $reference = null;
                if (is_array($item)) {
                    $reference = ReflectionReference::fromArrayElement($value, $key);
                }
                if ($reference !== null) {
                    /** @var object{getId: callable(): int} $referenceWithId */
                    $referenceWithId = $reference;
                    /** @psalm-suppress MixedMethodCall ReflectionReference::getId() is typed by PHP but not Psalm's PHP 8.1 stubs. */
                    $referenceId = (int) $referenceWithId->getId();
                    if (isset($activeReferences[$referenceId])) {
                        throw new InvalidArgumentException('Metadata contains a cyclic array.');
                    }
                    $activeReferences[$referenceId] = true;
                    self::assertSupportedValue($item, $depth + 1, $items, $activeReferences);
                    unset($activeReferences[$referenceId]);
                } else {
                    self::assertSupportedValue($item, $depth + 1, $items, $activeReferences);
                }
            }
            return;
        }
        if (!is_null($value) && !is_bool($value) && !is_int($value) && !is_float($value) && !is_string($value)) {
            throw new InvalidArgumentException('Metadata must contain only scalar values and arrays.');
        }
        if (is_string($value) && (strlen($value) > self::MAX_STRING_BYTES || str_contains($value, "\0"))) {
            throw new InvalidArgumentException('Metadata string is oversized or contains NUL.');
        }
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('Metadata cannot contain NaN or infinity.');
        }
    }
}
