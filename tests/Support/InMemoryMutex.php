<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use Yiisoft\Mutex\MutexInterface;
use Yiisoft\Scheduler\Lock\LockInterface;

/**
 * A manually controlled mutex for tests. A yiisoft/mutex implementation rather than a
 * {@see LockInterface}, so that what the tests lock a schedule with is
 * the adapter an application would use.
 */
class InMemoryMutex implements MutexInterface
{
    private bool $locked = false;

    public function acquire(int $timeout = 0): bool
    {
        if ($this->locked) {
            return false;
        }

        return $this->locked = true;
    }

    public function release(): void
    {
        $this->locked = false;
    }

    public function forceLock(): void
    {
        $this->locked = true;
    }
}
