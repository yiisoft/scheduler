<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Handler;

use Yiisoft\Queue\Message\MessageInterface;
use Yiisoft\Queue\QueueProducerInterface;
use Yiisoft\Scheduler\TaskContext;

/**
 * A task handler pushing message tasks to a queue via yiisoft/queue, while everything else goes
 * on to the handler behind it.
 *
 * Requires yiisoft/queue to be installed.
 */
final class QueueTaskHandler implements TaskHandlerInterface
{
    public function __construct(
        private readonly QueueProducerInterface $producer,
        private readonly TaskHandlerInterface $fallback = new CallableTaskHandler(),
    ) {}

    public function handle(mixed $task, TaskContext $context): mixed
    {
        if ($task instanceof MessageInterface) {
            return $this->producer->push($task);
        }

        // Everything else goes on, as the handler in front of this one passes on what it does
        // not recognise: the end of the chain is the one that answers for a task nobody runs,
        // and a handler put behind this one is reachable for the kinds it was added for.
        return $this->fallback->handle($task, $context);
    }
}
