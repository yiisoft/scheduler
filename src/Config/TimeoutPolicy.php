<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Config;

use InvalidArgumentException;

final class TimeoutPolicy
{
    public function __construct(public readonly ?float $softSeconds = null, public readonly ?float $hardSeconds = null)
    {
        foreach ([$softSeconds, $hardSeconds] as $value) {
            if ($value !== null && (!is_finite($value) || $value <= 0)) {
                throw new InvalidArgumentException('Timeouts must be positive finite seconds.');
            }
        }
        if ($softSeconds !== null && $hardSeconds !== null && $hardSeconds < $softSeconds) {
            throw new InvalidArgumentException('Hard timeout must be greater than or equal to soft timeout.');
        }
    }

    public function enforced(): bool
    {
        return $this->softSeconds !== null || $this->hardSeconds !== null;
    }

    public function merge(?self $override): self
    {
        return $override === null ? $this : new self($override->softSeconds ?? $this->softSeconds, $override->hardSeconds ?? $this->hardSeconds);
    }
}
