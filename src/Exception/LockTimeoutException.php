<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Exception;

use RuntimeException;

/**
 * The schedule lock could not be taken in time to record something about a run.
 *
 * Separate from {@see RuntimeException} because it says nothing about the run itself: another
 * process is holding the lock, which is ordinary on a schedule shared by several of them. What
 * was being recorded is simply not recorded, so the run stays claimed and is picked up again by
 * whichever process reaches it once the lock is free or the reclaim window runs out.
 */
final class LockTimeoutException extends RuntimeException {}
