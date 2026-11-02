<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Lock;

use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Lock\MutexLock;
use Yiisoft\Scheduler\Tests\Support\InMemoryMutex;

final class MutexLockTest extends TestCase
{
    public function testTheLockIsTheMutexBehindIt(): void
    {
        $mutex = new InMemoryMutex();
        $lock = new MutexLock($mutex);

        $this->assertTrue($lock->acquire());
        $this->assertFalse($mutex->acquire(), 'the mutex is held once the lock is');

        $lock->release();

        $this->assertTrue($mutex->acquire(), 'and free once it is released');
    }

    public function testTheTimeoutReachesTheMutexAndDefaultsToNotWaiting(): void
    {
        $mutex = new class extends InMemoryMutex {
            public int $timeout = -1;

            public function acquire(int $timeout = 0): bool
            {
                $this->timeout = $timeout;

                return true;
            }
        };

        $lock = new MutexLock($mutex);

        $lock->acquire(5);
        $this->assertSame(5, $mutex->timeout);

        // And not waiting is the default, as it is for a mutex: the answer is whether the
        // schedule was free at that moment, not whether it becomes free.
        $lock->acquire();
        $this->assertSame(0, $mutex->timeout);
    }
}
