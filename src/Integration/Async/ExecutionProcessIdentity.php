<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function strlen;

/** Identity facts sufficient to reject PID reuse on the supported local scope. */
final class ExecutionProcessIdentity
{
    public function __construct(
        public readonly int $pid,
        public readonly int $processGroup,
        public readonly int $session,
        public readonly string $startTicks,
        public readonly string $bootId,
    ) {
        if ($pid < 1 || $processGroup < 1 || $session < 1 || $startTicks === '' || $bootId === '' || strlen($startTicks) > 128 || strlen($bootId) > 128) {
            throw new InvalidArgumentException('Process identity is invalid.');
        }
    }
}
