<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Generator;

use function array_pad;
use function bin2hex;
use function explode;
use function fileinode;
use function function_exists;
use function gethostname;
use function getmypid;
use function is_file;
use function posix_get_last_error;
use function posix_kill;
use function random_bytes;

/**
 * Which process holds something, written down as "host/pid", and whether that process is gone.
 *
 * The checkpoint names the process a run is claimed by, and {@see ScheduleOwnership} the one a
 * schedule is planned by. Both are read back by another process to decide whether what it found
 * is still being looked after, so both have to write the same string and ask the same question
 * of it.
 *
 * @internal
 */
final class ProcessOwner
{
    /** The errno of kill(2) for a process that exists but is not ours to signal. */
    private const EPERM = 1;

    private static ?string $host = null;
    private static ?string $current = null;
    private static int|false|null $pid = null;

    /** This process, as "host/pid". */
    public static function current(): string
    {
        $pid = getmypid();

        // The pid is read every time and the string kept only while it stands. A fork copies
        // the memory of its parent, this string with it, so a worker forked from a process
        // that has claimed anything would otherwise claim as its parent: its claim would name
        // a pid that is still alive once the worker is gone, and the run would wait out the
        // reclaim window instead of being picked up as soon as the worker is collected.
        if (null !== self::$current && $pid === self::$pid) {
            return self::$current;
        }

        self::$pid = $pid;

        // A pid that cannot be read is given a token of its own rather than a zero: two such
        // processes on one host would otherwise share an owner, and each would read the other's
        // claim as its own and record an outcome for a run it never made. The token is kept for
        // as long as the pid stays unreadable, because an owner has to be the same string every
        // time; a fork of such a process is the one case this cannot tell apart, and its claim
        // is left to the window.
        return self::$current = self::host() . '/' . (false === $pid ? 'unknown-' . bin2hex(random_bytes(8)) : $pid);
    }

    /**
     * Only a process of this host can be asked about, and only with ext-posix. Anything else
     * reads as still there, and is left to the window around it: a run handed out twice before
     * the window is out is worse than one picked up late.
     */
    public static function host(): string
    {
        if (null !== self::$host) {
            return self::$host;
        }

        $host = gethostname() ?: 'unknown';

        // The pid namespace as well as the name, because a pid only means anything inside the
        // one it was taken in. Containers sharing the host's network namespace — `docker run
        // --network host`, a pod with `hostNetwork: true` — report the same name while keeping
        // their own pids, so a claim of one would be checked against a pid of the other and a
        // live run reclaimed at once, which is exactly what the window exists to prevent.
        $namespace = is_file('/proc/self/ns/pid') ? fileinode('/proc/self/ns/pid') : false;

        return self::$host = false === $namespace ? $host : $host . ':' . $namespace;
    }

    /**
     * Whether the process named by an owner string is known to be gone. Answers false wherever
     * it cannot be proved, so what the caller does about it is bounded by a window rather than
     * by this answer alone.
     */
    public static function isGone(string $owner): bool
    {
        [$host, $pid] = array_pad(explode('/', $owner, 2), 2, '');

        // A payload holding anything but an exact pid reads as a process still there.
        if ($host !== self::host() || !function_exists('posix_kill') || (string) (int) $pid !== $pid) {
            return false;
        }

        if ((int) $pid <= 0) {
            return false;
        }

        if (posix_kill((int) $pid, 0)) {
            return false;
        }

        // The signal is refused for a process of another user, which is still a process.
        return self::EPERM !== posix_get_last_error();
    }
}
