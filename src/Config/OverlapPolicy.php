<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Config;

use InvalidArgumentException;

use function is_string;

enum OverlapPolicy: string
{
    case ALLOW = 'allow';
    case SKIP = 'skip';

    public static function fromScalar(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Invalid overlap policy.');
        }
        return self::tryFrom($value) ?? throw new InvalidArgumentException('Invalid overlap policy.');
    }
}
