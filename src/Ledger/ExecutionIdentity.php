<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Ledger;

use DateTimeImmutable;
use Yiisoft\Scheduler\Exception\LogicException;

use function hash;
use function sprintf;
use function strlen;
use function trim;

/**
 * Which occurrence of which task of which source an execution record is for.
 *
 * The three together are what makes admission idempotent: a planner that is interrupted between
 * writing the record and acknowledging it builds the same identity on the next pass and finds
 * the record it already wrote, rather than admitting the occurrence a second time.
 *
 * The source is the configuration a schedule was built from, named by the application. Keeping
 * it in the identity is what lets two sources hold a task of the same id without sharing its
 * executions, and what makes every query of the ledger scoped to one of them.
 */
final class ExecutionIdentity
{
    public function __construct(
        public readonly string $source,
        public readonly string $taskId,
        public readonly DateTimeImmutable $occurrence,
    ) {
        if ('' === trim($source)) {
            throw new LogicException('The source of an execution must not be empty.');
        }

        if ('' === trim($taskId)) {
            throw new LogicException('The task id of an execution must not be empty.');
        }
    }

    /**
     * A stable key for the three, for a store that needs one value rather than three columns.
     * The occurrence is written to the microsecond, as the trigger produced it.
     *
     * Each field is written after its own length, because a source and a task id are whatever
     * the application calls them and either may hold the separator: joined on a colon alone,
     * `a:b` with task `c` and `a` with task `b:c` are one string, and two executions that have
     * nothing to do with each other would share a record.
     */
    public function key(): string
    {
        return hash('xxh3', sprintf(
            '%d:%s:%d:%s:%s',
            strlen($this->source),
            $this->source,
            strlen($this->taskId),
            $this->taskId,
            $this->occurrence->format('U.u'),
        ));
    }

    public function equals(self $other): bool
    {
        return $this->source === $other->source
            && $this->taskId === $other->taskId
            && $this->occurrence == $other->occurrence;
    }
}
