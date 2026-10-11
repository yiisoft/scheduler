<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Support;

use Yiisoft\Mutex\MutexInterface;

/**
 * A manually controlled mutex for tests.
 */
final class InMemoryMutex implements MutexInterface
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
