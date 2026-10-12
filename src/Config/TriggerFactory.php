<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Config;

use Yiisoft\Schedule\Trigger\TriggerInterface;

/** Backwards-compatible configuration namespace entry point. */
final class TriggerFactory
{
    public static function create(TriggerDefinition|array $descriptor): TriggerInterface
    {
        return \Yiisoft\Schedule\Trigger\TriggerFactory::create($descriptor);
    }
}
