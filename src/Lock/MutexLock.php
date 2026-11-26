<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Lock;

use Yiisoft\Mutex\MutexInterface;

/**
 * A schedule lock backed by a yiisoft/mutex implementation.
 *
 * ```php
 * $schedule->lock(new MutexLock($mutex));
 * ```
 *
 * Requires yiisoft/mutex to be installed. A schedule locked some other way needs neither this
 * class nor that package.
 */
final class MutexLock implements LockInterface
{
    public function __construct(
        private readonly MutexInterface $mutex,
    ) {}

    public function acquire(int $timeout = 0): bool
    {
        return $this->mutex->acquire($timeout);
    }

    public function release(): void
    {
        $this->mutex->release();
    }
}
