<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Handler;

use Yiisoft\Queue\Message\MessageInterface;
use Yiisoft\Queue\QueueProducerInterface;
use Yiisoft\Scheduler\Exception\LogicException;
use Yiisoft\Scheduler\TaskContext;

use function get_debug_type;
use function is_callable;
use function is_string;
use function sprintf;

/**
 * A task handler pushing message tasks to a queue via yiisoft/queue,
 * while callables and console command names still run in place.
 *
 * Requires yiisoft/queue to be installed.
 */
final class QueueTaskHandler implements TaskHandlerInterface
{
    public function __construct(
        private readonly QueueProducerInterface $producer,
        private readonly CallableTaskHandler $fallback = new CallableTaskHandler(),
    ) {}

    public function handle(mixed $task, TaskContext $context): mixed
    {
        if (is_string($task) || is_callable($task)) {
            return $this->fallback->handle($task, $context);
        }

        if ($task instanceof MessageInterface) {
            return $this->producer->push($task);
        }

        throw new LogicException(
            sprintf(
                'Unable to run a task of type "%s". Object tasks must implement "%s" (see GenericMessage); payloads cross the queue as scalars and arrays only.',
                get_debug_type($task),
                MessageInterface::class,
            ),
        );
    }
}
