<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Trigger;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Scheduler\Exception\LogicException;

use function is_string;
use function sprintf;

/**
 * A trigger backed by a cron expression, evaluated by dragonmantank/cron-expression.
 */
final class CronExpressionTrigger implements TriggerInterface
{
    private readonly CronExpression $cron;
    private readonly ?DateTimeZone $timezone;

    public function __construct(
        private readonly string $expression,
        DateTimeZone|string|null $timezone = null,
    ) {
        $this->cron = new CronExpression($expression);
        $this->timezone = is_string($timezone) ? ('' === $timezone ? null : new DateTimeZone($timezone)) : $timezone;
    }

    public function __toString(): string
    {
        return sprintf('cron(%s)', $this->expression);
    }

    public function getNextRunDate(DateTimeImmutable $lastRun): ?DateTimeImmutable
    {
        $next = $this->cron->getNextRunDate($lastRun, 0, false, $this->timezone?->getName());

        $next = DateTimeImmutable::createFromInterface($next);

        if ($next <= $lastRun) {
            throw new LogicException(
                sprintf('Cron expression "%s" produced a date that is not strictly after the last run.', $this->expression),
            );
        }

        return $next;
    }
}
