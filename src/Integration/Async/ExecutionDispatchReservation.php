<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function strlen;

/** Durable reservation made before a dispatcher attempts a launch. */
final class ExecutionDispatchReservation
{
    public function __construct(
        public readonly string $dispatcherId,
        public readonly int $reservedAt,
        public readonly int $deadline,
    ) {
        if ($dispatcherId === '' || strlen($dispatcherId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $dispatcherId) === 1 || $reservedAt < 0 || $deadline < $reservedAt) {
            throw new InvalidArgumentException('Dispatch reservation facts are invalid.');
        }
    }
}
