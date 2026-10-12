<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Integration\Async;

use InvalidArgumentException;

enum OccurrenceSkipReason: string
{
    case OVERLAP = 'overlap';
    case DOWNTIME = 'downtime';
    case RANGE_TRIM = 'range-trim';

    public static function fromString(string $reason): self
    {
        $normalized = self::tryFrom($reason);
        if ($normalized === null) {
            throw new InvalidArgumentException('Occurrence skip reason is not authorized.');
        }

        return $normalized;
    }
}
