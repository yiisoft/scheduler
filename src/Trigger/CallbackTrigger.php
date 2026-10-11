<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Trigger;

use Closure;
use DateTimeImmutable;
use Yiisoft\Scheduler\Exception\LogicException;

use function sprintf;

/**
 * A trigger delegating the next-run computation to a callback.
 */
final class CallbackTrigger implements TriggerInterface
{
    /**
     * @param Closure(DateTimeImmutable): (DateTimeImmutable|null) $callback Receives the last run
     * and returns the next run date, strictly after it, or null when exhausted.
     */
    public function __construct(
        private readonly Closure $callback,
        private readonly string $description = 'callback',
    ) {}

    public function __toString(): string
    {
        return $this->description;
    }

    public function getNextRunDate(DateTimeImmutable $lastRun): ?DateTimeImmutable
    {
        $next = ($this->callback)($lastRun);

        if (null !== $next && $next <= $lastRun) {
            throw new LogicException(
                sprintf('The trigger callback of "%s" must return a date strictly after the given one.', $this->description),
            );
        }

        return $next;
    }
}
