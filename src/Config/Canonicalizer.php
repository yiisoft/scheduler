<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Config;

use function array_is_list;
use function get_debug_type;
use function implode;
use function is_array;
use function ksort;
use function var_export;

/** @internal Stable representation used by configuration fingerprints. */
final class Canonicalizer
{
    public static function value(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = self::value($key) . ':' . self::value($item);
            }
            return '{' . implode(',', $parts) . '}';
        }
        return get_debug_type($value) . ':' . var_export($value, true);
    }
}
