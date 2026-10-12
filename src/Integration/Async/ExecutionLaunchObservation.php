<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async;

use InvalidArgumentException;

use function strlen;

/** Bounded observation; null facts deliberately do not imply that launch failed. */
final class ExecutionLaunchObservation
{
    public function __construct(
        public readonly ?int $pid,
        public readonly ?string $error,
        public readonly string $classification,
        public readonly int $observedAt,
        public readonly ?ExecutionProcessClosure $closure = null,
        public readonly ?ExecutionWorkerLaunchHandle $launchHandle = null,
    ) {
        if (($pid !== null && $pid < 1) || $observedAt < 0 || $classification === '' || strlen($classification) > 128 || preg_match('/[\x00-\x1F\x7F]/', $classification) === 1) {
            throw new InvalidArgumentException('Launch observation facts are invalid.');
        }
        if ($error !== null && ($error === '' || strlen($error) > 4096)) {
            throw new InvalidArgumentException('Launch observation error is invalid.');
        }
    }
}
