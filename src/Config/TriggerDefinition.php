<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Config;

use Yiisoft\Schedule\ScalarMetadata;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Cron\CronExpression;
use Throwable;

use function in_array;
use function is_int;
use function is_string;

final class TriggerDefinition
{
    public function __construct(public readonly string $reference, public readonly array $definition = [])
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $reference)) {
            throw new InvalidArgumentException('Invalid trigger reference.');
        }
        ScalarMetadata::assertAggregateSupported($definition);
    }

    /** Validate only the package-standard descriptors. Custom references are an extension boundary. */
    public function validate(): void
    {
        if (!in_array($this->reference, ['periodic', 'cron'], true)) {
            return;
        }
        $type = $this->definition['type'] ?? null;
        if ($type !== $this->reference) {
            throw new InvalidArgumentException('Standard trigger descriptor must contain its matching type.');
        }
        foreach ($this->definition as $key => $_) {
            if (!in_array($key, $this->reference === 'periodic'
                ? ['type', 'seconds', 'from', 'until']
                : ['type', 'expression', 'timezone', 'from', 'until'], true)) {
                throw new InvalidArgumentException('Unknown field in standard trigger descriptor.');
            }
        }
        if ($this->reference === 'periodic') {
            if (!is_int($this->definition['seconds'] ?? null) || $this->definition['seconds'] <= 0) {
                throw new InvalidArgumentException('Periodic trigger requires positive seconds.');
            }
        } else {
            $expression = $this->definition['expression'] ?? null;
            if (!is_string($expression) || $expression === '') {
                throw new InvalidArgumentException('Cron trigger requires an expression.');
            }
            try {
                new CronExpression($expression);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('Invalid cron expression.', 0, $e);
            }
            $timezone = $this->definition['timezone'] ?? null;
            if ($timezone !== null) {
                if (!is_string($timezone) || $timezone === '') {
                    throw new InvalidArgumentException('Cron timezone must be a non-empty string.');
                }
                try {
                    new DateTimeZone($timezone);
                } catch (Throwable $e) {
                    throw new InvalidArgumentException('Invalid cron timezone.', 0, $e);
                }
            }
        }
        $from = $this->date($this->definition['from'] ?? null);
        $until = $this->date($this->definition['until'] ?? null);
        if ($from !== null && $until !== null && $from > $until) {
            throw new InvalidArgumentException('Trigger from must not be after until.');
        }
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Trigger bounds must be UTC timestamps.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d\\TH:i:s\\Z') !== $value) {
            throw new InvalidArgumentException('Trigger bounds must be valid UTC timestamps.');
        }
        return $date;
    }
}
