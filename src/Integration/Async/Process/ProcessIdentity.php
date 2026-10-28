<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Process;

use InvalidArgumentException;
use Yiisoft\Scheduler\Integration\Async\ExecutionLaunchDescriptor;

use function strlen;

/** Facts read from the local Linux process table; never a command line. */
final class ProcessIdentity
{
    public function __construct(
        public readonly int $pid,
        public readonly int $processGroupId,
        public readonly int $sessionId,
        public readonly string $startTicks,
        public readonly string $bootId,
        public readonly ExecutionLaunchDescriptor $descriptor,
    ) {
        if ($pid < 1 || $processGroupId < 1 || $sessionId < 1 || $startTicks === '' || strlen($startTicks) > 32 || $bootId === '' || $descriptor->scopeKind !== ExecutionLaunchDescriptor::SCOPE_KIND) {
            throw new InvalidArgumentException('Process identity is invalid.');
        }
    }

    /** Compare every fact which can make a reused process look like our worker. */
    public function sameFacts(self $other): bool
    {
        return $this->pid === $other->pid
            && $this->processGroupId === $other->processGroupId
            && $this->sessionId === $other->sessionId
            && $this->startTicks === $other->startTicks
            && $this->bootId === $other->bootId
            && $this->descriptor->equals($other->descriptor);
    }
}
