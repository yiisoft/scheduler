<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Trigger;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Yiisoft\Scheduler\Config\TriggerDefinition;
use Yiisoft\Scheduler\ScalarMetadata;

use function array_key_exists;
use function in_array;
use function is_int;
use function is_string;
use function is_array;

/** Builds runtime triggers from the deliberately scalar prepared-configuration descriptor. */
final class TriggerFactory
{
    /** @var array<string, callable(array): mixed> */
    private static array $factories = [];

    /** @param callable(array): mixed $factory */
    public static function register(string $reference, callable $factory): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $reference)) {
            throw new InvalidArgumentException('Invalid trigger reference.');
        }
        self::$factories[$reference] = $factory;
    }

    public static function create(TriggerDefinition|array $descriptor): TriggerInterface
    {
        if (is_array($descriptor)) {
            ScalarMetadata::assertAggregateSupported($descriptor);
        }
        $reference = $descriptor instanceof TriggerDefinition ? $descriptor->reference : null;
        $definition = $descriptor instanceof TriggerDefinition ? $descriptor->definition : $descriptor;

        if ($reference !== null && !in_array($reference, ['periodic', 'cron'], true)) {
            return self::custom($reference, $definition);
        }
        if (!isset($definition['type']) || !is_string($definition['type'])) {
            throw new InvalidArgumentException('Trigger descriptor must contain a type.');
        }
        $type = $definition['type'];
        if ($reference !== null && $reference !== $type) {
            throw new InvalidArgumentException('Trigger reference does not match its descriptor type.');
        }
        return match ($reference ?? $type) {
            'periodic' => self::periodic($definition),
            'cron' => self::cron($definition),
            default => self::custom($type, $definition),
        };
    }

    private static function custom(string $reference, array $definition): TriggerInterface
    {
        if (!isset(self::$factories[$reference])) {
            throw new InvalidArgumentException('Unknown trigger reference: ' . $reference);
        }
        $trigger = (self::$factories[$reference])($definition);
        if (!$trigger instanceof TriggerInterface) {
            throw new InvalidArgumentException('Trigger factory must return a TriggerInterface.');
        }
        return $trigger;
    }

    private static function periodic(array $d): TriggerInterface
    {
        self::keys($d, ['type', 'seconds', 'from', 'until']);
        if (!isset($d['seconds']) || !is_int($d['seconds']) || $d['seconds'] <= 0) {
            throw new InvalidArgumentException('Periodic trigger requires positive integer seconds.');
        }
        /** @var array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable} $bounds */
        $bounds = self::bounds($d);
        [$from, $until] = $bounds;
        if ($from !== null && $until !== null && $from > $until) {
            throw new InvalidArgumentException('Trigger from must not be after until.');
        }
        return new PeriodicTrigger($d['seconds'], $from, $until);
    }

    private static function cron(array $d): TriggerInterface
    {
        self::keys($d, ['type', 'expression', 'timezone', 'from', 'until']);
        if (!isset($d['expression']) || !is_string($d['expression']) || $d['expression'] === '') {
            throw new InvalidArgumentException('Cron trigger requires an expression.');
        }
        $timezone = $d['timezone'] ?? null;
        if ($timezone !== null && (!is_string($timezone) || $timezone === '')) {
            throw new InvalidArgumentException('Cron timezone must be a non-empty string.');
        }
        /** @var string|null $timezone */
        $timezone = $timezone;
        /** @var array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable} $bounds */
        $bounds = self::bounds($d);
        [$from, $until] = $bounds;
        if ($from !== null && $until !== null && $from > $until) {
            throw new InvalidArgumentException('Trigger from must not be after until.');
        }
        return new CronExpressionTrigger($d['expression'], $timezone, $from, $until);
    }

    private static function bounds(array $d): array
    {
        foreach (['from', 'until'] as $key) {
            if (array_key_exists($key, $d) && $d[$key] !== null && !is_string($d[$key])) {
                throw new InvalidArgumentException('Trigger bounds must be UTC strings.');
            }
        }
        $from = $d['from'] ?? null;
        $until = $d['until'] ?? null;
        if ($from !== null && !is_string($from)) {
            throw new InvalidArgumentException('Trigger bounds must be UTC strings.');
        }
        if ($until !== null && !is_string($until)) {
            throw new InvalidArgumentException('Trigger bounds must be UTC strings.');
        }
        return [self::date($from), self::date($until)];
    }

    private static function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d\\TH:i:s\\Z') !== $value) {
            throw new InvalidArgumentException('Trigger bounds must use UTC RFC3339 seconds (…Z).');
        }
        return $date;
    }

    private static function keys(array $d, array $allowed): void
    {
        foreach ($d as $key => $_) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Malformed trigger descriptor.');
            }
        }
    }
}
