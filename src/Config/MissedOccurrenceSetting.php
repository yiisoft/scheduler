<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Config;

use InvalidArgumentException;

use function array_key_exists;
use function is_array;
use function is_int;
use function is_string;

final class MissedOccurrenceSetting
{
    public function __construct(
        public readonly MissedOccurrencePolicy $policy = MissedOccurrencePolicy::SKIP,
        public readonly ?int $max = null,
    ) {
        if ($policy === MissedOccurrencePolicy::ALL && ($max === null || $max < 1)) {
            throw new InvalidArgumentException('The all missed occurrence policy requires a positive max.');
        }
        if ($policy !== MissedOccurrencePolicy::ALL && $max !== null) {
            throw new InvalidArgumentException('Only the all missed occurrence policy may have a max.');
        }
    }

    public static function fromScalar(mixed $value): self
    {
        if ($value === null) {
            throw new InvalidArgumentException('Malformed missed occurrence policy.');
        }
        if (is_string($value)) {
            $policy = MissedOccurrencePolicy::tryFrom($value);
            if ($policy === null) {
                throw new InvalidArgumentException('Invalid missed occurrence policy.');
            }
            return new self($policy);
        }
        if (!is_array($value) || array_diff(array_keys($value), ['policy', 'max']) !== [] || !array_key_exists('policy', $value) || !is_string($value['policy'])) {
            throw new InvalidArgumentException('Malformed missed occurrence policy.');
        }
        $policy = MissedOccurrencePolicy::tryFrom($value['policy']);
        if ($policy === null || (array_key_exists('max', $value) && !is_int($value['max']))) {
            throw new InvalidArgumentException('Malformed missed occurrence policy.');
        }
        return new self($policy, $value['max'] ?? null);
    }
}
