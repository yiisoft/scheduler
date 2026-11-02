<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Lock;

/**
 * Keeps two processes from running one schedule at the same time.
 *
 * A scheduler acquires the lock of its schedule before a tick and releases it after. One that
 * cannot acquire it skips the tick: another process is already running those tasks.
 *
 * The two methods are those of `Yiisoft\Mutex\MutexInterface`, which {@see MutexLock} adapts,
 * so an application already holding a mutex has one; an application locking some other way
 * implements this and needs no mutex package at all.
 */
interface LockInterface
{
    /**
     * Acquires the lock.
     *
     * @param int $timeout How long to wait for it, in seconds. Zero, the default, means not to
     * wait: the answer is whether it was free at that moment.
     *
     * @return bool Whether the lock is now held by this process.
     */
    public function acquire(int $timeout = 0): bool;

    /**
     * Releases the lock. Does nothing when it is not held.
     */
    public function release(): void;
}
