<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Handler;

use PHPUnit\Framework\TestCase;
use Yiisoft\Queue\Message\GenericMessage;
use Yiisoft\Queue\Message\MessageInterface;
use Yiisoft\Queue\MessageStatus;
use Yiisoft\Queue\QueueProducerInterface;
use Yiisoft\Scheduler\Handler\QueueTaskHandler;
use Yiisoft\Scheduler\Handler\TaskHandlerInterface;
use Yiisoft\Scheduler\TaskContext;
use Yiisoft\Scheduler\Tests\Support\TestClock;
use Yiisoft\Scheduler\Trigger\CronExpressionTrigger;
use LogicException;

final class QueueTaskHandlerTest extends TestCase
{
    public function testMessageIsPushedToTheQueue(): void
    {
        $producer = new class implements QueueProducerInterface {
            public array $messages = [];

            public function push(MessageInterface $message): MessageInterface
            {
                $this->messages[] = $message;

                return $message;
            }

            public function status(string|int $id): MessageStatus
            {
                return MessageStatus::WAITING;
            }

            public function getQueueName(): string
            {
                return 'default';
            }
        };

        $handler = new QueueTaskHandler($producer);
        $context = new TaskContext('default', 'id', new CronExpressionTrigger('* * * * *'), (new TestClock())->now(), null);

        $handler->handle(GenericMessage::fromPayload('report.generate', ['id' => 42]), $context);

        $this->assertCount(1, $producer->messages);
        $this->assertSame('report.generate', $producer->messages[0]->getType());
    }

    public function testCallableStillRunsInPlace(): void
    {
        $handler = new QueueTaskHandler(
            new class implements QueueProducerInterface {
                public function push(MessageInterface $message): MessageInterface
                {
                    throw new LogicException('Must not be called.');
                }

                public function status(string|int $id): MessageStatus
                {
                    return MessageStatus::WAITING;
                }

                public function getQueueName(): string
                {
                    return 'default';
                }
            },
        );

        $context = new TaskContext('default', 'id', new CronExpressionTrigger('* * * * *'), (new TestClock())->now(), null);

        $this->assertSame('ran', $handler->handle(static fn(): string => 'ran', $context));
    }

    public function testATaskThisHandlerDoesNotRecogniseReachesTheOneBehindIt(): void
    {
        // The kind a handler of an application is added for: this one answering for it would
        // put that handler out of reach, which is the whole point of a chain.
        $handler = new QueueTaskHandler(
            new class implements QueueProducerInterface {
                public function push(MessageInterface $message): MessageInterface
                {
                    throw new LogicException('Must not be called.');
                }

                public function status(string|int $id): MessageStatus
                {
                    return MessageStatus::WAITING;
                }

                public function getQueueName(): string
                {
                    return 'default';
                }
            },
            new class implements TaskHandlerInterface {
                public function handle(mixed $task, TaskContext $context): mixed
                {
                    return 'handled by the application';
                }
            },
        );

        $context = new TaskContext('default', 'id', new CronExpressionTrigger('* * * * *'), (new TestClock())->now(), null);

        $this->assertSame('handled by the application', $handler->handle(['a', 'b'], $context));
    }
}
