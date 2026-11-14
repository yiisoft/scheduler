<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

use Yiisoft\Scheduler\Exception\LogicException;

use function sprintf;
use function strlen;

/**
 * Enough of a started process to recognise it again after a restart, and no more.
 *
 * Bounded on purpose. A durable record is read by a process that did not write it, possibly on
 * another host and under another deployment, so what goes in has to be small, scalar and free
 * of anything an application would mind seeing in a database: no command line, no environment,
 * no arguments.
 *
 * None of these fields prove ownership on their own. A pid is reused, a host name is shared by
 * containers, and a start time is only meaningful beside the boot it was taken in. Together
 * they let an observer say "this is not the process I launched" with confidence, which is the
 * question recovery actually asks.
 */
final class LaunchDescription
{
    private const MAX_LENGTH = 255;

    public function __construct(
        public readonly string $host,
        public readonly int $pid,
        /** Identifies the boot the pid was taken in, where the platform offers one. */
        public readonly ?string $bootId = null,
        /** The process start time as the platform reports it, which tells a reused pid apart. */
        public readonly ?string $startedAt = null,
    ) {
        if ($pid <= 0) {
            throw new LogicException('The pid of a launch must be greater than zero.');
        }

        foreach (['host' => $host, 'bootId' => $bootId, 'startedAt' => $startedAt] as $name => $value) {
            if (null !== $value && strlen($value) > self::MAX_LENGTH) {
                throw new LogicException(sprintf(
                    'The %s of a launch must be at most %d characters.',
                    $name,
                    self::MAX_LENGTH,
                ));
            }
        }
    }

    /** Whether this describes the same process, as far as the recorded facts can say. */
    public function describes(self $other): bool
    {
        return $this->host === $other->host
            && $this->pid === $other->pid
            && $this->bootId === $other->bootId
            && $this->startedAt === $other->startedAt;
    }
}
